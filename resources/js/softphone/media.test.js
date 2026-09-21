import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from './api.js';
import { AnswerSession, PHASE } from './media.js';
import { ANSWER_SDP, FakePeerConnection, OFFER_SDP, fakeApi, fakeAudio, fakeStream } from './test-helpers.js';

function make(overrides = {}) {
    const api = overrides.api ?? fakeApi();
    const stream = overrides.stream ?? fakeStream();
    const audioEl = overrides.audioEl ?? fakeAudio();
    const session = new AnswerSession({
        callId: 'c1',
        api,
        stream,
        audioEl,
        env: { RTCPeerConnection: FakePeerConnection },
        connectTimeoutMs: 20000,
        ...overrides.options,
    });

    return { api, stream, audioEl, session };
}

const pc = () => FakePeerConnection.instances.at(-1);

/** Runs the flow until it parks waiting for the peer connection. */
async function runUntilWaiting(session) {
    const running = session.run();
    await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));

    return running;
}

beforeEach(() => {
    FakePeerConnection.instances = [];
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('answer session lifecycle (mocked WebRTC — not proof of real audio)', () => {
    it('follows the documented order and does not accept until the peer connection is connected', async () => {
        const { api, session } = make();
        const order = [];
        api.claim.mockImplementation(async () => { order.push('claim'); return { session: { sdp: OFFER_SDP } }; });
        api.answer.mockImplementation(async () => { order.push('answer'); return {}; });
        api.accept.mockImplementation(async () => { order.push('accept'); return {}; });

        const running = runUntilWaiting(session);
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));

        expect(order).toEqual(['claim', 'answer']);
        expect(pc().log.map(([step]) => step)).toEqual(['setRemoteDescription', 'addTrack', 'createAnswer', 'setLocalDescription']);
        expect(api.accept).not.toHaveBeenCalled();

        pc().setState('connecting');
        expect(api.accept).not.toHaveBeenCalled();

        pc().setState('connected');
        await running;

        expect(order).toEqual(['claim', 'answer', 'accept']);
        expect(session.phase).toBe(PHASE.CONNECTED);
    });

    it('uses the offer exactly as Meta sent it and sends the browser\'s answer exactly as produced', async () => {
        const { api, session } = make();

        const running = runUntilWaiting(session);
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));
        pc().setState('connected');
        await running;

        expect(pc().log[0]).toEqual(['setRemoteDescription', { type: 'offer', sdp: OFFER_SDP }]);
        expect(api.answer).toHaveBeenCalledWith('c1', ANSWER_SDP);
        expect(api.answer.mock.calls[0][1]).toBe(ANSWER_SDP);
    });

    it('starts with no STUN or TURN servers', async () => {
        const { session } = make();

        const running = runUntilWaiting(session);
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));

        expect(pc().config).toEqual({ iceServers: [] });
        pc().setState('connected');
        await running;
    });

    it('keeps the microphone disabled and the audio silent until the accept response', async () => {
        const { api, stream, audioEl, session } = make();
        let release;
        api.accept.mockImplementation(() => new Promise((resolve) => { release = resolve; }));

        const running = session.run();
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));

        expect(pc().log.find(([step]) => step === 'addTrack')[1]).toBe(false);
        expect(stream.tracks[0].enabled).toBe(false);

        pc().setState('connected');
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.ACCEPTING));

        expect(stream.tracks[0].enabled).toBe(false);
        expect(audioEl.play).not.toHaveBeenCalled();

        release({});
        await running;

        expect(stream.tracks[0].enabled).toBe(true);
        expect(audioEl.play).toHaveBeenCalledTimes(1);
        expect(session.phase).toBe(PHASE.CONNECTED);
    });

    it('attaches the remote stream to the audio element', async () => {
        const { audioEl, session } = make();
        const running = runUntilWaiting(session);
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));

        const remote = { id: 'remote' };
        pc().ontrack({ streams: [remote] });
        pc().setState('connected');
        await running;

        expect(audioEl.srcObject).toBe(remote);
    });

    it('fails without ever accepting when the connection fails before accept', async () => {
        const { api, session } = make();
        const running = session.run();
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));

        pc().setState('failed');
        await running;

        expect(session.phase).toBe(PHASE.FAILED);
        expect(session.error).toMatchObject({ code: 'ice_failed', retryable: false });
        expect(api.accept).not.toHaveBeenCalled();
    });

    it('fails when the connection is not established in time', async () => {
        vi.useFakeTimers();
        const { api, session } = make({ options: { connectTimeoutMs: 5000, timers: { setTimeout: (...a) => setTimeout(...a), clearTimeout: (...a) => clearTimeout(...a) } } });

        const running = session.run();
        await vi.advanceTimersByTimeAsync(5001);
        await running;

        expect(session.phase).toBe(PHASE.FAILED);
        expect(session.error.code).toBe('ice_timeout');
        expect(api.accept).not.toHaveBeenCalled();
    });

    it('does not accept a call that is already connected twice', async () => {
        const { api, session } = make();
        const running = session.run();
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));
        pc().setState('connected');
        await running;

        await session.retry();
        await session.run();

        expect(api.accept).toHaveBeenCalledTimes(1);
        expect(api.claim).toHaveBeenCalledTimes(1);
    });
});

describe('answer session — errors and retry', () => {
    it('a retryable pre_accept failure is retried with the very same SDP, without claiming again', async () => {
        const { api, session } = make();
        api.answer.mockRejectedValueOnce(new ApiError({ status: 503, code: 'meta_unavailable', message: 'Try again.', retryable: true }));

        await session.run();

        expect(session.phase).toBe(PHASE.FAILED);
        expect(session.error).toMatchObject({ code: 'meta_unavailable', retryable: true });

        const retrying = session.retry();
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));
        pc().setState('connected');
        await retrying;

        expect(api.claim).toHaveBeenCalledTimes(1);
        expect(api.answer).toHaveBeenCalledTimes(2);
        expect(api.answer.mock.calls[1][1]).toBe(api.answer.mock.calls[0][1]);
        expect(FakePeerConnection.instances).toHaveLength(1);
        expect(session.phase).toBe(PHASE.CONNECTED);
    });

    it('a retryable accept failure retries only accept', async () => {
        const { api, session } = make();
        api.accept.mockRejectedValueOnce(new ApiError({ status: 503, code: 'meta_unavailable', message: 'Try again.', retryable: true }));

        const running = session.run();
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));
        pc().setState('connected');
        await running;

        expect(session.phase).toBe(PHASE.FAILED);

        await session.retry();

        expect(api.claim).toHaveBeenCalledTimes(1);
        expect(api.answer).toHaveBeenCalledTimes(1);
        expect(api.accept).toHaveBeenCalledTimes(2);
        expect(session.phase).toBe(PHASE.CONNECTED);
    });

    it('a permanent failure is not retryable', async () => {
        const { api, session } = make();
        api.answer.mockRejectedValueOnce(new ApiError({ status: 502, code: 'meta_rejected', message: 'WhatsApp could not connect this call.', retryable: false }));

        await session.run();
        await session.retry();

        expect(session.error).toMatchObject({ code: 'meta_rejected', retryable: false });
        expect(api.answer).toHaveBeenCalledTimes(1);
    });

    it('a claim conflict keeps its server code', async () => {
        const { api, session } = make();
        api.claim.mockRejectedValueOnce(new ApiError({ status: 409, code: 'already_claimed', message: 'x', retryable: false }));

        await session.run();

        expect(session.error.code).toBe('already_claimed');
        expect(FakePeerConnection.instances).toHaveLength(0);
    });

    it('fails cleanly when the claim carries no offer', async () => {
        const { api, session } = make();
        api.claim.mockResolvedValueOnce({ call: {} });

        await session.run();

        expect(session.phase).toBe(PHASE.FAILED);
        expect(session.error.code).toBe('no_offer');
    });

    it('a browser WebRTC exception becomes a safe, non-retryable error', async () => {
        const { session } = make();
        vi.spyOn(FakePeerConnection.prototype, 'setRemoteDescription').mockRejectedValueOnce(new Error('InvalidStateError: raw browser text'));

        await session.run();

        expect(session.error).toEqual({ code: 'webrtc', message: 'Could not set up the audio connection.', retryable: false, status: 0 });
    });
});

describe('answer session — live call', () => {
    async function connected(overrides) {
        const made = make(overrides);
        const running = made.session.run();
        await vi.waitFor(() => expect(made.session.phase).toBe(PHASE.CONNECTING));
        pc().setState('connected');
        await running;

        return made;
    }

    it('mute is client-only: it toggles the track and makes no request', async () => {
        const { api, stream, session } = await connected();
        const before = { claim: api.claim.mock.calls.length, answer: api.answer.mock.calls.length, accept: api.accept.mock.calls.length, reject: api.reject.mock.calls.length };

        session.setMuted(true);
        expect(stream.tracks[0].enabled).toBe(false);
        session.setMuted(false);
        expect(stream.tracks[0].enabled).toBe(true);

        expect({ claim: api.claim.mock.calls.length, answer: api.answer.mock.calls.length, accept: api.accept.mock.calls.length, reject: api.reject.mock.calls.length }).toEqual(before);
    });

    it('a mute chosen before the call connects survives going live', async () => {
        const { api, stream, session } = make();
        session.setMuted(true);
        const running = session.run();
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));
        pc().setState('connected');
        await running;

        expect(stream.tracks[0].enabled).toBe(false);
        expect(api.accept).toHaveBeenCalledTimes(1);
    });

    it('blocked autoplay is surfaced and an explicit click enables the audio', async () => {
        const audioEl = fakeAudio({ playRejects: true });
        const { session } = await connected({ audioEl });

        expect(session.phase).toBe(PHASE.CONNECTED);
        expect(session.audioBlocked).toBe(true);

        audioEl.rejectPlay = false;
        await session.enableAudio();

        expect(session.audioBlocked).toBe(false);
    });

    it('a dropped connection shows reconnecting, recovers, and a failed one ends as an error', async () => {
        const { session } = await connected();

        pc().setState('disconnected');
        expect(session.reconnecting).toBe(true);

        pc().setState('connected');
        expect(session.reconnecting).toBe(false);

        pc().setState('failed');
        expect(session.phase).toBe(PHASE.FAILED);
        expect(session.error.code).toBe('connection_lost');
    });

    it('close() releases the peer connection, the microphone and the audio, and is idempotent', async () => {
        const { stream, audioEl, session } = await connected();
        const connection = pc();

        session.close();
        session.close();

        expect(connection.closed).toBe(true);
        expect(connection.onconnectionstatechange).toBeNull();
        expect(stream.tracks[0].stopped).toBe(true);
        expect(audioEl.pause).toHaveBeenCalled();
        expect(audioEl.srcObject).toBeNull();
        expect(session.phase).toBe(PHASE.CLOSED);
    });

    it('closing while waiting ends the flow quietly without accepting', async () => {
        const { api, session } = make();
        const running = session.run();
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));

        session.close();
        await running;

        expect(api.accept).not.toHaveBeenCalled();
        expect(session.phase).toBe(PHASE.CLOSED);
    });

    it('never writes an SDP to the console', async () => {
        const spies = ['log', 'info', 'warn', 'error', 'debug'].map((method) => vi.spyOn(console, method).mockImplementation(() => {}));
        const { api, session } = make();
        api.answer.mockRejectedValueOnce(new ApiError({ status: 503, code: 'meta_unavailable', message: 'x', retryable: true }));

        await session.run();
        const retrying = session.retry();
        await vi.waitFor(() => expect(session.phase).toBe(PHASE.CONNECTING));
        pc().setState('connected');
        await retrying;
        session.close();

        expect(spies.every((spy) => spy.mock.calls.length === 0)).toBe(true);
    });
});
