/**
 * WhatsApp inbound calling — a synthesized "you have an incoming call" ring
 * (Web Audio, no asset/library), played only while an inbound call is
 * Ringing and unclaimed in this tab. Mirrors outbound-media.js's
 * startRingback()/stopRingback() pattern exactly (same Web Audio primitives,
 * one oscillator/gain pair retriggered per cadence tick, same "never throws
 * into the call lifecycle — a missing or autoplay-blocked AudioContext
 * simply means no audible cue" principle) but with its own louder, more
 * attention-getting two-burst cadence: this alerts the callee, ringback
 * reassures the caller, so they're deliberately not the same sound.
 *
 * Kept entirely outside SoftphoneController/the media sessions — it reacts
 * to the controller's rendered snapshot from index.js, never touches call
 * state itself, so it cannot affect answer/decline/hangup or any existing
 * behavior.
 */
export function createRingtone({ AudioContext, timers = { setInterval: (...a) => setInterval(...a), clearInterval: (...a) => clearInterval(...a) } } = {}) {
    let active = null;

    const ringBurst = (context, gain) => {
        try {
            const t = context.currentTime;
            gain.gain.cancelScheduledValues(t);
            gain.gain.setValueAtTime(0, t);
            gain.gain.linearRampToValueAtTime(0.12, t + 0.03);
            gain.gain.linearRampToValueAtTime(0, t + 0.35);
            gain.gain.linearRampToValueAtTime(0.12, t + 0.5);
            gain.gain.linearRampToValueAtTime(0, t + 0.85);
        } catch {
            // A scheduling failure never affects the call itself.
        }
    };

    /**
     * Idempotent — a second start() while already ringing is a no-op, same
     * as startRingback().
     */
    function start() {
        if (active) {
            return;
        }

        if (typeof AudioContext !== 'function') {
            return;
        }

        try {
            const context = new AudioContext();
            const gain = context.createGain();
            const oscillator = context.createOscillator();

            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.value = 0;
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start();

            ringBurst(context, gain);
            const interval = timers.setInterval(() => ringBurst(context, gain), 2000);

            active = { context, gain, oscillator, interval };
        } catch {
            active = null;
        }
    }

    /**
     * Fully idempotent — safe whether ringing is active or not, and safe to
     * call more than once.
     */
    function stop() {
        if (!active) {
            return;
        }

        const { context, gain, oscillator, interval } = active;
        active = null;

        try {
            timers.clearInterval(interval);
        } catch {
            // Ignore.
        }

        try {
            oscillator.stop();
        } catch {
            // Already stopped.
        }

        try {
            oscillator.disconnect();
            gain.disconnect();
        } catch {
            // Already disconnected.
        }

        try {
            context.close?.();
        } catch {
            // Already closed.
        }
    }

    return {
        start,
        stop,
        isActive: () => active !== null,
    };
}
