import { createApi } from './api.js';
import { createCallingAppHandoff } from './calling-app.js';
import { MODE, SoftphoneController } from './controller.js';
import { registerCallDragButton } from './drag-button.js';
import { createOwnership } from './ownership.js';
import { createPushSubscriber } from './push.js';
import { subscribeToCalls } from './realtime.js';
import { createRingtone } from './ringtone.js';

/**
 * WhatsApp inbound calling — the Alpine component behind the global softphone
 * shell (resources/views/components/softphone.blade.php). It only renders the
 * controller's plain-data snapshot and forwards clicks; everything with state
 * lives in SoftphoneController, which is kept OUT of Alpine's reactive proxy (a
 * closure variable) so its peer connection and microphone stream are never wrapped.
 *
 * Baseline networking: no STUN and no TURN (`iceServers: []`). Meta's offer is
 * ICE-lite with public host candidates; whether real networks need a relay is a
 * Step 7 question, and adding one later means passing servers here.
 */
export function registerSoftphone(alpine) {
    alpine.data('softphone', (config) => {
        let controller = null;
        let clock = null;
        let ringtone = null;
        let pushSubscriber = null;
        let callingApp = null;

        const media = (audioEl) => () => {
            const supported = typeof window.RTCPeerConnection === 'function' && typeof navigator.mediaDevices?.getUserMedia === 'function';

            return {
                supported,
                audioEl,
                getMicrophone: () => navigator.mediaDevices.getUserMedia({ audio: true }),
                // AudioContext: only OutboundSession's ringback uses this —
                // undefined here simply means ringback silently never starts.
                // mediaDevices: the earpiece/loudspeaker toggle — undefined
                // here (older browsers) simply means it's never available.
                env: { RTCPeerConnection: window.RTCPeerConnection, iceServers: [], AudioContext: window.AudioContext, mediaDevices: navigator.mediaDevices },
            };
        };

        return {
            view: { loaded: false, offline: false, offlineText: '', cards: [], notices: [], mediaBusy: false },
            minimized: {},
            // Web Push — 'unsupported' until init() resolves the real state,
            // so the "Enable notifications" prompt stays hidden rather than
            // flashing then disappearing on a browser that can't do this at
            // all (Safari without the PWA installed, pre-Chromium Edge, …).
            notificationsPermission: 'unsupported',
            notificationsSubscribed: false,
            notificationsBusy: false,
            notificationsError: null,
            // Cancel hides the prompt for this browser without touching the
            // actual Notification permission (which JS can never revoke) —
            // deliberately NOT persisted: plain in-memory state means it
            // resets on the next full page load, which is what "ask again
            // next login" means in practice (login is a full reload, not a
            // wire:navigate transition the @persist'd softphone survives).
            notificationsDismissed: false,

            init() {
                // Calling companion app — see calling-app.js. Detection only;
                // with no paired phone or no installed app this never acts.
                callingApp = createCallingAppHandoff({ config: { ...(config.callingApp ?? {}), userId: config.userId } });

                if (!window.Echo) {
                    return;
                }

                ringtone = createRingtone({
                    AudioContext: window.AudioContext,
                    audioUrl: config.ringtoneUrl ?? null,
                    mediaSession: navigator.mediaSession,
                    MediaMetadataClass: window.MediaMetadata,
                });

                pushSubscriber = createPushSubscriber({ vapidPublicKey: config.vapidPublicKey ?? null, urls: config.pushUrls ?? {} });
                this.notificationsPermission = pushSubscriber.permission();
                pushSubscriber.isSubscribed().then((subscribed) => { this.notificationsSubscribed = subscribed; });

                controller = new SoftphoneController({
                    api: createApi({ urls: config.urls }),
                    createMedia: media(this.$refs.remoteAudio),
                    ownership: createOwnership(),
                    subscribe: ({ onEvent, onReconnect }) => subscribeToCalls({ echo: window.Echo, userId: config.userId, tenantId: config.tenantId, onEvent, onReconnect }),
                    texts: config.texts ?? {},
                    pollMs: config.pollMs,
                    heartbeatMs: config.heartbeatMs,
                    staleMs: config.staleMs,
                    onChange: (snapshot) => {
                        this.view = snapshot;

                        // Ring for as long as any unclaimed inbound call is
                        // showing — start()/stop() are both idempotent, so
                        // this re-evaluation on every snapshot never
                        // restarts an already-playing ring.
                        const ringingCard = snapshot.cards.find((card) => card.mode === MODE.RINGING);

                        if (ringingCard) {
                            ringtone?.start({
                                title: config.texts?.incomingCallTitle ?? 'Incoming WhatsApp call',
                                artist: ringingCard.lead?.name || ringingCard.lead?.phone || '',
                            });
                        } else {
                            ringtone?.stop();
                        }
                    },
                });

                controller.start();
                // Once a second so countdowns and call timers stay current.
                clock = setInterval(() => controller.notify(), 1000);

                // WhatsApp Outbound Calling — the global softphone's bridge
                // to a "Call" button anywhere else on the page (e.g. the Lead
                // detail view), which has no direct reference to this Alpine
                // component instance. A plain window event, not a Livewire/
                // Alpine store, so it works the same regardless of where the
                // button lives; window.dispatchEvent(new CustomEvent(
                // 'softphone:call', { detail: { leadId, leadName, leadPhone } })).
                // leadName/leadPhone are carried along purely so the card can
                // render instantly (see controller.js's pendingLead) — never
                // trusted as anything beyond display text.
                this.onSoftphoneCall = (event) => this.callOutbound(event.detail?.leadId, event.detail?.leadName, event.detail?.leadPhone);
                window.addEventListener('softphone:call', this.onSoftphoneCall);

                // Unlocks the ringtone's audio element on the very first tap
                // anywhere on the page, well before any real call could
                // arrive — see ringtone.js's prime() docblock for why a call
                // that starts ringing while the tab is already backgrounded
                // otherwise needs this to have already happened once. One
                // real user gesture per page load is enough; removes itself
                // immediately either way.
                this.onFirstInteraction = () => {
                    document.removeEventListener('pointerdown', this.onFirstInteraction);
                    document.removeEventListener('keydown', this.onFirstInteraction);
                    ringtone?.prime();
                };
                document.addEventListener('pointerdown', this.onFirstInteraction, { once: true, passive: true });
                document.addEventListener('keydown', this.onFirstInteraction, { once: true });
            },

            destroy() {
                clearInterval(clock);
                window.removeEventListener('softphone:call', this.onSoftphoneCall);
                document.removeEventListener('pointerdown', this.onFirstInteraction);
                document.removeEventListener('keydown', this.onFirstInteraction);
                controller?.stop();
                ringtone?.stop();
                ringtone = null;
            },

            answer(id) {
                // Called straight from the click so the microphone prompt is a user gesture.
                return controller?.answer(id);
            },
            decline: (id) => controller?.decline(id),
            hangup: (id) => controller?.hangup(id),
            dismiss: (id) => controller?.dismiss(id),
            retry: () => controller?.retry(),
            toggleMute: () => controller?.toggleMute(),
            toggleSpeaker: () => controller?.toggleSpeaker(),
            enableAudio: () => controller?.enableAudio(),

            callOutbound(leadId, leadName = null, leadPhone = null) {
                // The paired OptiFin Calling app on this phone takes the call
                // when it can (earpiece support) — still inside the click's
                // gesture, which the app launch needs. Otherwise, unchanged:
                if (leadId && callingApp?.tryCall(leadId, leadName ?? null)) {
                    return undefined;
                }

                // Called straight from the click (or the window event above)
                // so the microphone prompt is a user gesture.
                return leadId ? controller?.callOutbound(leadId, { id: leadId, name: leadName ?? null, phone: leadPhone ?? null }) : undefined;
            },
            cancelOutboundCall: () => controller?.cancelOutboundCall(),

            // Called straight from the click (Notification.requestPermission()
            // requires a real user gesture, same reasoning as answer()/
            // callOutbound() above needing one for the microphone prompt).
            async enableNotifications() {
                if (this.notificationsBusy) {
                    return;
                }

                this.notificationsBusy = true;
                this.notificationsError = null;

                const result = (await pushSubscriber?.enable()) ?? { ok: false, reason: 'unsupported' };

                if (result.error) {
                    // Diagnostic only — never shown to the user, see push.js.
                    console.error('[softphone] push subscribe failed:', result.error);
                }

                this.notificationsBusy = false;
                this.notificationsPermission = pushSubscriber?.permission() ?? 'unsupported';
                this.notificationsSubscribed = result.ok;

                if (!result.ok) {
                    const genericError = config.texts?.genericError ?? 'Something went wrong with the call.';

                    this.notificationsError = {
                        unsupported: config.texts?.notificationsUnsupported ?? 'This browser cannot show call notifications here.',
                        'permission-denied': config.texts?.notificationsPermissionDenied ?? 'Notifications are blocked for this site in your browser settings.',
                        'registration-failed': genericError,
                        'subscribe-failed': genericError,
                    }[result.reason] ?? genericError;
                }
            },

            async disableNotifications() {
                await pushSubscriber?.disable();
                this.notificationsSubscribed = false;
            },

            dismissNotificationsPrompt() {
                this.notificationsDismissed = true;
                this.notificationsError = null;
            },

            toggleMinimized(id) {
                this.minimized = { ...this.minimized, [id]: !this.minimized[id] };
            },

            format(seconds) {
                if (seconds === null || seconds === undefined) {
                    return '';
                }

                const m = Math.floor(seconds / 60);
                const s = String(seconds % 60).padStart(2, '0');

                return `${m}:${s}`;
            },
        };
    });
}

// The Alpine instance is provided by Livewire; register before or after it starts.
if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        registerSoftphone(window.Alpine);
        registerCallDragButton(window.Alpine);
    });

    if (window.Alpine?.data) {
        registerSoftphone(window.Alpine);
        registerCallDragButton(window.Alpine);
    }
}
