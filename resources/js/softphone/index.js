import { createApi } from './api.js';
import { SoftphoneController } from './controller.js';
import { registerCallDragButton } from './drag-button.js';
import { createOwnership } from './ownership.js';
import { subscribeToCalls } from './realtime.js';

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

        const media = (audioEl) => () => {
            const supported = typeof window.RTCPeerConnection === 'function' && typeof navigator.mediaDevices?.getUserMedia === 'function';

            return {
                supported,
                audioEl,
                getMicrophone: () => navigator.mediaDevices.getUserMedia({ audio: true }),
                // AudioContext: only OutboundSession's ringback uses this —
                // undefined here simply means ringback silently never starts.
                env: { RTCPeerConnection: window.RTCPeerConnection, iceServers: [], AudioContext: window.AudioContext },
            };
        };

        return {
            view: { loaded: false, offline: false, offlineText: '', cards: [], notices: [], mediaBusy: false },
            minimized: {},

            init() {
                if (!window.Echo) {
                    return;
                }

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
            },

            destroy() {
                clearInterval(clock);
                window.removeEventListener('softphone:call', this.onSoftphoneCall);
                controller?.stop();
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
            enableAudio: () => controller?.enableAudio(),

            callOutbound(leadId, leadName = null, leadPhone = null) {
                // Called straight from the click (or the window event above)
                // so the microphone prompt is a user gesture.
                return leadId ? controller?.callOutbound(leadId, { id: leadId, name: leadName ?? null, phone: leadPhone ?? null }) : undefined;
            },
            cancelOutboundCall: () => controller?.cancelOutboundCall(),

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
