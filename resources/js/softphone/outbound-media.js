import { canToggleSpeaker, findAudioOutputDevices, setAudioOutputDevice } from './audio-output.js';
import { SessionError } from './media.js';

/**
 * WhatsApp Outbound Calling — the browser side of PLACING one call. A
 * deliberately separate class from AnswerSession (never mutated into a
 * shared bidirectional abstraction) because the two flows are mirror
 * images, not variations of one another:
 *
 *   createOffer(), setLocalDescription()
 *   → POST the offer to Laravel (InitiateOutboundCall) — returns Meta's wacid
 *   → RINGING: nothing more to do here until the controller notices (via its
 *     normal realtime-nudged /calls/active refresh — never trusted as state,
 *     exactly like every other softphone event) that this call's status is
 *     now Answering (Meta's SDP answer has arrived — NOT evidence the
 *     consumer physically answered, see RecordOutboundCallEvent), and calls
 *     applyAnswer()
 *   → GET the cached answer (FetchOutboundCallAnswer)
 *   → setRemoteDescription(answer)
 *   → wait for the real peer connection (waitForConnection()) — the phase
 *     deliberately stays RINGING through this whole step (ringback keeps
 *     playing), since reaching Answering must never by itself imply
 *     Connected
 *   → confirm the real WebRTC connection server-side
 *     (ConfirmOutboundCallConnected — the one and only writer of this
 *     call's Connected status/connected_at), then goLive()
 *
 * An earlier version of this class also waited on Meta's own `ACCEPTED`
 * call-status webhook (RecordOutboundCallStatusEvent's consumer_accepted_at)
 * as a second, independent gate before confirming. That was reverted
 * 2026-09-26: live validation against real Meta test calls showed the
 * consumer genuinely answering and talking while Meta never sent an
 * ACCEPTED webhook at all (only RINGING arrived) — hard-blocking on it left
 * every real call stuck in RINGING forever, no audio, timer never starting.
 * Real ICE-connect, while imperfect (it only proves this browser reached
 * Meta's own media relay, not that the consumer physically picked up), is
 * therefore the only currently-reliable signal Meta actually provides for
 * this. RecordOutboundCallStatusEvent/consumer_accepted_at still exist
 * server-side (harmless, and genuinely populated on the rare call where Meta
 * does send ACCEPTED) but nothing in this class waits on them anymore.
 *
 * This two-step split (initiate() / applyAnswer(), rather than one
 * sequential run() like AnswerSession) exists because outbound genuinely
 * has an external wait in the middle with no local polling of its own —
 * the controller's existing realtime/refresh machinery is what drives it,
 * not this class.
 *
 * Shared mechanics (peer connection wiring, ICE-connect waiting, mute,
 * goLive, teardown) are re-implemented here rather than imported: they are
 * private to AnswerSession, and duplicating ~40 lines of already-tested
 * logic is a smaller, more isolated risk than changing that class's
 * visibility or extracting a shared base out of a closed, hardened file.
 */

export const OUTBOUND_PHASE = {
    INITIATING: 'initiating',
    RINGING: 'ringing',
    CONNECTING: 'connecting',
    CONNECTED: 'connected',
    FAILED: 'failed',
    CLOSED: 'closed',
};

const isIceConnected = (state) => state === 'connected' || state === 'completed';

export class OutboundSession {
    constructor({
        leadId,
        api,
        stream,
        env,
        audioEl,
        connectTimeoutMs = 20000,
        onChange = () => {},
        timers = {
            setTimeout: (...a) => setTimeout(...a),
            clearTimeout: (...a) => clearTimeout(...a),
            setInterval: (...a) => setInterval(...a),
            clearInterval: (...a) => clearInterval(...a),
        },
        now = () => Date.now(),
    }) {
        this.leadId = leadId;
        this.api = api;
        this.stream = stream;
        this.env = env;
        this.audioEl = audioEl;
        this.connectTimeoutMs = connectTimeoutMs;
        this.onChange = onChange;
        this.timers = timers;
        this.now = now;

        this.phase = OUTBOUND_PHASE.INITIATING;
        this.callId = null;
        this.error = null;
        this.muted = false;
        this.audioBlocked = false;
        this.reconnecting = false;
        this.connectedAt = null;
        this.answerApplied = false;
        // Earpiece/loudspeaker toggle — see AnswerSession.detectSpeakerSupport()'s
        // own docblock (identical reasoning, duplicated rather than shared:
        // this class deliberately never imports AnswerSession's own methods,
        // see this file's own top docblock).
        this.speakerOn = false;
        this.speakerAvailable = false;
        this.speakerDevices = { speakerId: null, earpieceId: null };

        this.pc = null;
        this.waiter = null;
        this.closed = false;

        // Ringback (agent-side "the phone is ringing" cue) — owned entirely
        // here, never by the controller/template. Non-null only while a
        // synthesized Web Audio tone is actually playing; see
        // startRingback()/stopRingback().
        this.ringback = null;
    }

    setPhase(phase) {
        if (this.closed) {
            return;
        }

        if (this.phase === OUTBOUND_PHASE.RINGING && phase !== OUTBOUND_PHASE.RINGING) {
            this.stopRingback();
        }

        this.phase = phase;
        this.onChange();

        if (phase === OUTBOUND_PHASE.RINGING) {
            this.startRingback();
        }
    }

    /**
     * Must be invoked directly from the "Call" click: the microphone
     * permission prompt is requested from that user gesture — the stream is
     * already held by the time this runs (the controller asks for it first,
     * exactly like answer()), so this only ever builds the offer and sends it.
     */
    async initiate() {
        if (this.closed) {
            return;
        }

        this.error = null;

        try {
            const pc = new this.env.RTCPeerConnection({ iceServers: this.env.iceServers ?? [] });
            this.pc = pc;

            pc.ontrack = (event) => {
                const remote = event?.streams?.[0];

                if (remote && this.audioEl) {
                    this.audioEl.srcObject = remote;
                }
            };
            pc.onconnectionstatechange = () => this.onPeerState();
            pc.oniceconnectionstatechange = () => this.onPeerState();

            for (const track of this.stream.getAudioTracks()) {
                track.enabled = false;
                pc.addTrack(track, this.stream);
            }

            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);

            const result = await this.api.initiateOutbound(this.leadId, offer.sdp);
            const callId = result?.call?.id;

            if (typeof callId !== 'string' || callId === '') {
                throw new SessionError('no_call_id', 'Could not start the call.');
            }

            this.callId = callId;
            this.setPhase(OUTBOUND_PHASE.RINGING);
        } catch (error) {
            if (!this.closed) {
                this.fail(error);
            }
        }
    }

    /**
     * Mirrors AnswerSession.retry()'s exact contract: only from FAILED, only
     * when the failure was marked retryable, resuming from where applyAnswer()
     * left off (answerApplied was already reset to false on that failure) —
     * an explicit agent "Retry" click, never an automatic silent re-attempt
     * from the controller's own refresh nudge.
     */
    async retry() {
        if (this.phase === OUTBOUND_PHASE.FAILED && this.error?.retryable && this.callId) {
            this.phase = OUTBOUND_PHASE.RINGING;
            await this.applyAnswer();
        }
    }

    /**
     * Step 2 — called by the controller, never polled internally. A no-op
     * unless still Ringing with a known call id, so a duplicate nudge (a
     * realtime event landing twice, an overlapping refresh) can never
     * re-apply an answer or restart negotiation.
     *
     * Deliberately does NOT leave RINGING here (ringback keeps playing) —
     * reaching Answering must never by itself imply Connected. Only once the
     * real peer connection comes up (waitForConnection()) does this confirm
     * the call server-side and go live.
     */
    async applyAnswer() {
        if (this.closed || this.answerApplied || this.phase !== OUTBOUND_PHASE.RINGING || !this.callId) {
            return;
        }

        this.answerApplied = true;

        try {
            const result = await this.api.outboundAnswer(this.callId);
            const sdp = result?.session?.sdp;

            if (typeof sdp !== 'string' || sdp === '') {
                throw new SessionError('no_answer', 'The call could not be connected.');
            }

            await this.pc.setRemoteDescription({ type: 'answer', sdp });
            await this.waitForConnection();

            // Only the browser's own confirmed real WebRTC connection may
            // complete the call server-side — Meta's SDP answer alone
            // (which is what got us this far) is not evidence the consumer
            // physically answered. A lost race (the call already ended
            // server-side — a terminate webhook or the stale sweep won
            // first) throws here exactly like any other applyAnswer()
            // failure, and is handled identically below: a non-retryable
            // fail(), never a locally-declared Connected.
            await this.api.confirmOutboundConnected(this.callId);
            await this.goLive();
        } catch (error) {
            // Allow the next controller-driven nudge (another refresh, e.g.
            // after a transient answer-fetch failure) to retry.
            this.answerApplied = false;

            if (!this.closed) {
                this.fail(error);
            }
        }
    }

    peerState() {
        return this.pc?.connectionState ?? this.pc?.iceConnectionState;
    }

    waitForConnection() {
        return new Promise((resolve, reject) => {
            if (isIceConnected(this.peerState())) {
                resolve();

                return;
            }

            const timer = this.timers.setTimeout(() => {
                this.waiter = null;
                reject(new SessionError('ice_timeout', 'Could not establish the audio connection in time.'));
            }, this.connectTimeoutMs);

            this.waiter = {
                resolve: () => {
                    this.timers.clearTimeout(timer);
                    this.waiter = null;
                    resolve();
                },
                reject: (error) => {
                    this.timers.clearTimeout(timer);
                    this.waiter = null;
                    reject(error);
                },
            };
        });
    }

    onPeerState() {
        if (this.closed) {
            return;
        }

        const state = this.peerState();

        if (this.waiter) {
            if (isIceConnected(state)) {
                this.waiter.resolve();
            } else if (state === 'failed' || state === 'closed') {
                this.waiter.reject(new SessionError('ice_failed', 'The audio connection could not be established.'));
            }

            return;
        }

        if (this.phase !== OUTBOUND_PHASE.CONNECTED) {
            return;
        }

        if (state === 'disconnected') {
            this.reconnecting = true;
            this.onChange();
        } else if (isIceConnected(state)) {
            this.reconnecting = false;
            this.onChange();
        } else if (state === 'failed') {
            this.fail(new SessionError('connection_lost', 'The audio connection was lost.'));
        }
    }

    async goLive() {
        for (const track of this.stream.getAudioTracks()) {
            track.enabled = !this.muted;
        }

        this.connectedAt = this.now();
        this.setPhase(OUTBOUND_PHASE.CONNECTED);

        try {
            await this.audioEl?.play();
        } catch {
            this.audioBlocked = true;
            this.onChange();
        }

        this.detectSpeakerSupport();
    }

    /**
     * Only meaningful once connected — see AnswerSession's identical method.
     */
    async detectSpeakerSupport() {
        this.speakerDevices = await findAudioOutputDevices(this.env?.mediaDevices);
        this.speakerAvailable = canToggleSpeaker(this.speakerDevices);
        this.onChange();
    }

    /**
     * See AnswerSession.toggleSpeaker() — identical behavior.
     */
    async toggleSpeaker() {
        if (!this.speakerAvailable) {
            return false;
        }

        const next = !this.speakerOn;
        const deviceId = next ? this.speakerDevices.speakerId : this.speakerDevices.earpieceId;
        const ok = await setAudioOutputDevice(this.audioEl, deviceId);

        if (ok) {
            this.speakerOn = next;
        }

        this.onChange();

        return ok;
    }

    async enableAudio() {
        try {
            await this.audioEl?.play();
            this.audioBlocked = false;
        } catch {
            this.audioBlocked = true;
        }

        this.onChange();
    }

    setMuted(muted) {
        this.muted = muted;

        if (this.phase === OUTBOUND_PHASE.CONNECTED) {
            for (const track of this.stream.getAudioTracks()) {
                track.enabled = !muted;
            }
        }

        this.onChange();
    }

    fail(error) {
        const known = typeof error?.code === 'string' && typeof error?.retryable === 'boolean';

        this.error = known
            ? { code: error.code, message: error.message, retryable: error.retryable, status: error.status ?? 0 }
            : { code: 'webrtc', message: 'Could not set up the audio connection.', retryable: false, status: 0 };

        this.setPhase(OUTBOUND_PHASE.FAILED);
    }

    /**
     * Idempotent teardown — identical shape to AnswerSession.close(), plus
     * ringback cleanup. This is the one path that can end a still-RINGING
     * session WITHOUT ever going through setPhase() (e.g. agent cancel,
     * or an externally-observed termination noticed by the controller) —
     * stopRingback() is itself a no-op if ringback isn't active, so calling
     * it here is always safe regardless of whether setPhase() already
     * stopped it.
     */
    close() {
        if (this.closed) {
            return;
        }

        this.stopRingback();

        this.closed = true;
        this.phase = OUTBOUND_PHASE.CLOSED;
        this.waiter?.reject?.(new SessionError('closed', 'closed'));

        if (this.pc) {
            this.pc.ontrack = null;
            this.pc.onconnectionstatechange = null;
            this.pc.oniceconnectionstatechange = null;

            try {
                this.pc.close();
            } catch {
                // Already closed.
            }
        }

        for (const track of this.stream?.getTracks?.() ?? []) {
            try {
                track.stop();
            } catch {
                // Already stopped.
            }
        }

        if (this.audioEl) {
            try {
                this.audioEl.pause();
            } catch {
                // Nothing playing.
            }

            this.audioEl.srcObject = null;
        }

        this.pc = null;
    }

    /**
     * A subtle, synthesized ring cadence (Web Audio — no asset, no library)
     * played only while the CONSUMER's phone is genuinely ringing. One
     * oscillator/gain pair for the session's lifetime; only the gain
     * envelope is retriggered per cadence tick, so no node is repeatedly
     * created/destroyed. `env.AudioContext` mirrors how `env.RTCPeerConnection`
     * is already injected — undefined (unsupported browser, or none wired by
     * the caller) simply means this silently never starts.
     *
     * Never throws into the call lifecycle: any construction or scheduling
     * failure (missing AudioContext, autoplay/user-activation rejection,
     * anything else) leaves `this.ringback` null and the call proceeds with
     * no audible cue — ringback is a non-essential affordance, never a gate.
     */
    startRingback() {
        if (this.closed || this.ringback) {
            return;
        }

        const AudioContextClass = this.env?.AudioContext;

        if (typeof AudioContextClass !== 'function') {
            return;
        }

        try {
            const context = new AudioContextClass();
            const gain = context.createGain();
            const oscillator = context.createOscillator();

            oscillator.type = 'sine';
            oscillator.frequency.value = 425;
            gain.gain.value = 0;
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start();

            const cadenceMs = 3000;
            const burstSeconds = 0.4;
            const peakGain = 0.05;

            const ringBurst = () => {
                try {
                    const t = context.currentTime;
                    gain.gain.cancelScheduledValues(t);
                    gain.gain.setValueAtTime(0, t);
                    gain.gain.linearRampToValueAtTime(peakGain, t + 0.05);
                    gain.gain.linearRampToValueAtTime(0, t + burstSeconds);
                } catch {
                    // A scheduling failure never affects the call itself.
                }
            };

            ringBurst();
            const interval = this.timers.setInterval(ringBurst, cadenceMs);

            this.ringback = { context, gain, oscillator, interval };
        } catch {
            this.ringback = null;
        }
    }

    /**
     * Fully idempotent — safe whether ringback is active or not, and safe
     * to call more than once (setPhase() and close() can each reach it for
     * the same transition).
     */
    stopRingback() {
        if (!this.ringback) {
            return;
        }

        const { context, gain, oscillator, interval } = this.ringback;
        this.ringback = null;

        try {
            this.timers.clearInterval(interval);
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
}
