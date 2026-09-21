/**
 * WhatsApp inbound calling — which browser TAB owns the media session of a call.
 *
 * A peer connection lives in exactly one tab's memory and cannot be shared or
 * recreated, so when a second tab of the same agent is open it must know the call
 * is being handled elsewhere instead of offering "Answer" again. This is an
 * ephemeral BroadcastChannel handshake only: it carries a call id and a tab id —
 * never an SDP or a credential — and nothing is written to any storage. If
 * BroadcastChannel is unavailable it degrades to "no other owners known" (the
 * server also refuses a second, different SDP for the same call).
 */
export function createOwnership({
    channelFactory = (name) => new BroadcastChannel(name),
    tabId = Math.random().toString(36).slice(2),
    now = () => Date.now(),
    timers = { setInterval: (...a) => setInterval(...a), clearInterval: (...a) => clearInterval(...a) },
    ttlMs = 15000,
    beatMs = 5000,
} = {}) {
    let channel = null;
    let mine = null;
    let beat = null;
    const others = new Map();
    const listeners = new Set();

    const emit = () => listeners.forEach((listener) => listener());
    const post = (message) => {
        try {
            channel?.postMessage({ ...message, tab: tabId });
        } catch {
            // A closed channel just means no coordination.
        }
    };

    try {
        channel = channelFactory('whatsapp-softphone');
        channel.onmessage = (event) => {
            const message = event?.data;

            if (!message || message.tab === tabId) {
                return;
            }

            if (message.t === 'own' && typeof message.callId === 'string') {
                const previous = others.get(message.tab)?.callId;
                others.set(message.tab, { callId: message.callId, seen: now() });

                if (previous !== message.callId) {
                    emit();
                }
            } else if (message.t === 'release') {
                if (others.delete(message.tab)) {
                    emit();
                }
            } else if (message.t === 'hello' && mine !== null) {
                post({ t: 'own', callId: mine });
            }
        };
        post({ t: 'hello' });
    } catch {
        channel = null;
    }

    return {
        get available() {
            return channel !== null;
        },

        /**
         * Call ids other tabs currently own. Owners that stopped announcing (a
         * closed or crashed tab) age out after ttlMs.
         */
        otherOwnedCallIds() {
            const owned = new Set();

            for (const [tab, entry] of others) {
                if (now() - entry.seen > ttlMs) {
                    others.delete(tab);
                } else {
                    owned.add(entry.callId);
                }
            }

            return owned;
        },

        claim(callId) {
            mine = callId;
            post({ t: 'own', callId });
            beat ??= timers.setInterval(() => mine !== null && post({ t: 'own', callId: mine }), beatMs);
        },

        release() {
            if (mine === null) {
                return;
            }

            mine = null;
            post({ t: 'release' });

            if (beat !== null) {
                timers.clearInterval(beat);
                beat = null;
            }
        },

        onChange(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },

        close() {
            this.release();

            try {
                channel?.close();
            } catch {
                // Already closed.
            }

            channel = null;
        },
    };
}
