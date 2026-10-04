/**
 * WhatsApp inbound calling — plays either a tenant's uploaded custom MP3
 * ringtone (Client Admin setting, see CallingSettings\Index) or, when none
 * is set, a synthesized "you have an incoming call" ring (Web Audio, no
 * asset/library) — played only while an inbound call is Ringing and
 * unclaimed in this tab. The synthesized tone mirrors outbound-media.js's
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
export function createRingtone({
    AudioContext,
    timers = { setInterval: (...a) => setInterval(...a), clearInterval: (...a) => clearInterval(...a) },
    audioUrl = null,
    createAudioElement = (src) => new Audio(src),
    mediaSession = typeof navigator === 'undefined' ? undefined : navigator.mediaSession,
    MediaMetadataClass = typeof MediaMetadata === 'undefined' ? undefined : MediaMetadata,
} = {}) {
    let active = null;
    let customAudio = null;

    /**
     * Declares a real Media Session for the ring (title/artist instead of
     * Chrome's own guess at the page title, and no seek/track controls,
     * which make no sense for a ringing call) — mobile OSes specifically
     * exempt an active media session from background-tab suspension, which
     * is *why* the ring keeps playing audibly after the tab is backgrounded
     * or the screen is locked. Declared for both the custom-audio and
     * synthesized paths alike, since it's independent of how the sound is
     * actually produced. Best-effort, like everything else here: an
     * unsupported API or a throwing call never affects the ring itself.
     */
    function setMediaSession(metadata) {
        if (!mediaSession) {
            return;
        }

        try {
            if (typeof MediaMetadataClass === 'function') {
                mediaSession.metadata = new MediaMetadataClass({
                    title: metadata?.title ?? 'Incoming WhatsApp call',
                    artist: metadata?.artist ?? '',
                });
            }

            mediaSession.playbackState = 'playing';

            for (const action of ['seekbackward', 'seekforward', 'seekto', 'previoustrack', 'nexttrack', 'stop', 'skipad']) {
                try {
                    mediaSession.setActionHandler(action, null);
                } catch {
                    // Not every action is supported everywhere.
                }
            }
        } catch {
            // Never blocks the ring itself.
        }
    }

    function clearMediaSession() {
        if (!mediaSession) {
            return;
        }

        try {
            mediaSession.playbackState = 'none';
            mediaSession.metadata = null;
        } catch {
            // Ignore.
        }
    }

    /**
     * The custom-MP3 path, entirely separate from the synthesized one below
     * — a tenant with no ringtoneUrl configured never touches this at all,
     * so its behavior is byte-for-byte unchanged from before this existed.
     */
    function startCustom() {
        if (active) {
            return;
        }

        try {
            const audio = customAudio ?? (customAudio = createAudioElement(audioUrl));

            audio.loop = true;
            audio.currentTime = 0;

            // A promise rejection here (autoplay blocked) must never throw
            // into the call lifecycle — same principle as the synthesized
            // path's try/catch below.
            audio.play()?.catch(() => {});

            active = { kind: 'custom', audio };
        } catch {
            active = null;
        }
    }

    function stopCustom() {
        const { audio } = active;
        active = null;

        try {
            audio.pause();
            audio.currentTime = 0;
        } catch {
            // Ignore.
        }
    }

    /**
     * Unlocks the custom-audio element ahead of time, muted, so the FIRST
     * real ring of a page session doesn't have to be the one that
     * establishes "this element is allowed to autoplay" with the browser.
     * That distinction matters specifically for a call that arrives while
     * the tab is already backgrounded: a browser generally allows
     * *continuing* audio that already played once in this session, but
     * blocks *starting* new audio from a background tab with no recent
     * interaction — silently, no error, which is indistinguishable from
     * "broken" without this context. Call once, from a real user gesture,
     * as early as possible (see index.js's one-time first-interaction
     * listener) — a no-op for the synthesized path (nothing persists across
     * its own start()/stop() cycle to usefully pre-unlock).
     */
    function prime() {
        if (!audioUrl || active) {
            return;
        }

        try {
            const audio = customAudio ?? (customAudio = createAudioElement(audioUrl));
            const wasMuted = audio.muted;
            audio.muted = true;

            const reset = () => {
                try {
                    audio.pause();
                    audio.currentTime = 0;
                } catch {
                    // Ignore.
                }

                audio.muted = wasMuted;
            };

            const playing = audio.play();

            if (playing?.then) {
                playing.then(reset).catch(() => { audio.muted = wasMuted; });
            } else {
                reset();
            }
        } catch {
            // Best effort — start() still tries its own unlock later, same
            // as before this existed.
        }
    }

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
     * as startRingback() (so a later call with different `metadata`, e.g. a
     * second overlapping ring with a different caller, doesn't update the
     * Media Session already showing — same simplification already accepted
     * for the cadence itself, see this file's own top docblock).
     *
     * @param {{title?: string, artist?: string}} [metadata]
     */
    function start(metadata) {
        if (active) {
            return;
        }

        setMediaSession(metadata);

        if (audioUrl) {
            startCustom();

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

        clearMediaSession();

        if (active.kind === 'custom') {
            stopCustom();

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
        prime,
        isActive: () => active !== null,
    };
}
