/**
 * WhatsApp inbound calling — the browser side of answering ONE call.
 *
 * The lifecycle follows Meta's documented order and standard browser WebRTC:
 *
 *   claim (returns Meta's SDP offer)
 *   → RTCPeerConnection, setRemoteDescription(offer)
 *   → add the microphone track, DISABLED (no media may flow before accept)
 *   → createAnswer, setLocalDescription
 *   → POST the exact answer SDP the browser produced (Meta `pre_accept`)
 *   → wait for the peer connection to be connected (Meta: "the WebRTC connection
 *     is established before calling the Accept Call endpoint")
 *   → POST accept (Meta `accept`)
 *   → only after that 200: enable the microphone track and play the remote audio
 *
 * Deliberately NOT here: SDP munging (the browser's answer is sent untouched),
 * trickle-ICE signalling (there is no such endpoint), STUN/TURN (baseline is no
 * ICE servers; `env.iceServers` exists only so a later step can add them), and any
 * storage of the SDP — it lives in this object's memory and dies with it. The
 * SDP is never logged.
 *
 * A failed step can be retried from that step (the server's idempotent answer /
 * accept endpoints and the in-memory answer string make a retry safe), and the
 * browser-mocked unit tests exercise this ordering — they do not prove that real
 * audio connects, which needs a real Meta number, browser and network (Step 7).
 */

export const PHASE = {
    CLAIMING: 'claiming',
    ANSWERING: 'answering',
    CONNECTING: 'connecting',
    ACCEPTING: 'accepting',
    CONNECTED: 'connected',
    FAILED: 'failed',
    CLOSED: 'closed',
};

export class SessionError extends Error {
    constructor(code, message, retryable = false) {
        super(message);
        this.name = 'SessionError';
        this.code = code;
        this.retryable = retryable;
    }
}

const isConnected = (state) => state === 'connected' || state === 'completed';

export class AnswerSession {
    constructor({ callId, api, stream, env, audioEl, connectTimeoutMs = 20000, onChange = () => {}, timers = { setTimeout: (...a) => setTimeout(...a), clearTimeout: (...a) => clearTimeout(...a) } }) {
        this.callId = callId;
        this.api = api;
        this.stream = stream;
        this.env = env;
        this.audioEl = audioEl;
        this.connectTimeoutMs = connectTimeoutMs;
        this.onChange = onChange;
        this.timers = timers;

        this.phase = PHASE.CLAIMING;
        this.stage = 'claim';
        this.error = null;
        this.muted = false;
        this.audioBlocked = false;
        this.reconnecting = false;

        this.pc = null;
        this.answerSdp = null;
        this.waiter = null;
        this.closed = false;
    }

    setPhase(phase) {
        if (this.closed) {
            return;
        }

        this.phase = phase;
        this.onChange();
    }

    /**
     * Runs (or resumes) the answer flow from the current stage.
     */
    async run() {
        if (this.closed) {
            return;
        }

        this.error = null;

        try {
            if (this.stage === 'claim') {
                this.setPhase(PHASE.CLAIMING);
                const claimed = await this.api.claim(this.callId);
                await this.negotiate(claimed?.session?.sdp);
                this.stage = 'answer';
            }

            if (this.stage === 'answer') {
                this.setPhase(PHASE.ANSWERING);
                await this.api.answer(this.callId, this.answerSdp);
                this.stage = 'wait';
            }

            if (this.stage === 'wait') {
                this.setPhase(PHASE.CONNECTING);
                await this.waitForConnection();
                this.stage = 'accept';
            }

            if (this.stage === 'accept') {
                this.setPhase(PHASE.ACCEPTING);
                await this.api.accept(this.callId);
                this.stage = 'done';
                await this.goLive();
            }
        } catch (error) {
            if (!this.closed) {
                this.fail(error);
            }
        }
    }

    async retry() {
        if (this.phase === PHASE.FAILED && this.error?.retryable) {
            await this.run();
        }
    }

    async negotiate(offerSdp) {
        if (typeof offerSdp !== 'string' || offerSdp === '') {
            throw new SessionError('no_offer', 'The call could not be answered.');
        }

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

        await pc.setRemoteDescription({ type: 'offer', sdp: offerSdp });

        for (const track of this.stream.getAudioTracks()) {
            track.enabled = false;
            pc.addTrack(track, this.stream);
        }

        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);

        // Exactly what the browser produced — no munging, no normalisation here.
        this.answerSdp = answer.sdp;
    }

    peerState() {
        return this.pc?.connectionState ?? this.pc?.iceConnectionState;
    }

    waitForConnection() {
        return new Promise((resolve, reject) => {
            if (isConnected(this.peerState())) {
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
            if (isConnected(state)) {
                this.waiter.resolve();
            } else if (state === 'failed' || state === 'closed') {
                this.waiter.reject(new SessionError('ice_failed', 'The audio connection could not be established.'));
            }

            return;
        }

        if (this.phase !== PHASE.CONNECTED) {
            return;
        }

        if (state === 'disconnected') {
            this.reconnecting = true;
            this.onChange();
        } else if (isConnected(state)) {
            this.reconnecting = false;
            this.onChange();
        } else if (state === 'failed') {
            this.fail(new SessionError('connection_lost', 'The audio connection was lost.'));
        }
    }

    /**
     * After the accept 200 — and only then — audio flows: the microphone is
     * enabled (unless the agent muted) and the remote audio starts playing. A
     * browser that blocks autoplay leaves `audioBlocked` set for an explicit
     * "enable audio" click.
     */
    async goLive() {
        for (const track of this.stream.getAudioTracks()) {
            track.enabled = !this.muted;
        }

        this.setPhase(PHASE.CONNECTED);

        try {
            await this.audioEl?.play();
        } catch {
            this.audioBlocked = true;
            this.onChange();
        }
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

    /**
     * Client-only: toggles the microphone track. No request, no stored state.
     */
    setMuted(muted) {
        this.muted = muted;

        if (this.phase === PHASE.CONNECTED) {
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

        this.setPhase(PHASE.FAILED);
    }

    /**
     * Idempotent teardown: the peer connection, the microphone and the audio
     * element are all released.
     */
    close() {
        if (this.closed) {
            return;
        }

        this.closed = true;
        this.phase = PHASE.CLOSED;
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
}
