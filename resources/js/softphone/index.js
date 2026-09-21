import { createApi } from './api.js';
import { SoftphoneController } from './controller.js';
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
                env: { RTCPeerConnection: window.RTCPeerConnection, iceServers: [] },
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
                    subscribe: ({ onEvent, onReconnect }) => subscribeToCalls({ echo: window.Echo, userId: config.userId, onEvent, onReconnect }),
                    texts: config.texts ?? {},
                    onChange: (snapshot) => {
                        this.view = snapshot;
                    },
                });

                controller.start();
                // Once a second so countdowns and call timers stay current.
                clock = setInterval(() => controller.notify(), 1000);
            },

            destroy() {
                clearInterval(clock);
                controller?.stop();
            },

            answer(id) {
                // Called straight from the click so the microphone prompt is a user gesture.
                return controller?.answer(id);
            },
            decline: (id) => controller?.decline(id),
            dismiss: (id) => controller?.dismiss(id),
            retry: () => controller?.retry(),
            toggleMute: () => controller?.toggleMute(),
            enableAudio: () => controller?.enableAudio(),

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
    document.addEventListener('alpine:init', () => registerSoftphone(window.Alpine));

    if (window.Alpine?.data) {
        registerSoftphone(window.Alpine);
    }
}
