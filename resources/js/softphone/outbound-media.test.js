import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AnswerSession } from './media.js';
import { OUTBOUND_PHASE, OutboundSession } from './outbound-media.js';
import { ANSWER_SDP, FakePeerConnection, OFFER_SDP, fakeAudio, fakeApi, fakeStream } from './test-helpers.js';

/*
 * WhatsApp Outbound Calling — the browser side of PLACING a call. Mirrors
 * media.test.js's mocking approach (a fake RTCPeerConnection whose call log
 * proves ordering, not real WebRTC) but exercises OutboundSession's own,
 * separate two-step (initiate() / applyAnswer()) shape — never AnswerSession.
 */

/**
 * A minimal Web Audio double proving ordering/idempotency, not real audio —
 * same spirit as FakePeerConnection. No env.AudioContext is wired into the
 * default setup() below, so every pre-existing test in this file is
 * completely unaffected: ringback simply never starts without it.
 */
class FakeGainNode {
    constructor() {
        this.gain = { value: 0, setValueAtTime: vi.fn(), linearRampToValueAtTime: vi.fn(), cancelScheduledValues: vi.fn() };
        this.disconnected = false;
    }

    connect() {}

    disconnect() {
        this.disconnected = true;
    }
}

class FakeOscillatorNode {
    constructor() {
        this.frequency = { value: 0 };
        this.type = 'sine';
        this.started = false;
        this.stopped = false;
        this.disconnected = false;
    }

    connect() {}

    disconnect() {
        this.disconnected = true;
    }

    start() {
        this.started = true;
    }

    stop() {
        this.stopped = true;
    }
}

class FakeAudioContext {
    constructor() {
        this.currentTime = 0;
        this.closed = false;
        this.destination = {};
        FakeAudioContext.instances.push(this);
    }

    createGain() {
        this.lastGain = new FakeGainNode();

        return this.lastGain;
    }

    createOscillator() {
        this.lastOscillator = new FakeOscillatorNode();

        return this.lastOscillator;
    }

    close() {
        this.closed = true;

        return Promise.resolve();
    }
}
FakeAudioContext.instances = [];

class ThrowingAudioContext {
    constructor() {
        throw new Error('AudioContext is not supported.');
    }
}

function setup(apiOverrides = {}, envOverrides = {}) {
    const api = fakeApi(apiOverrides);
    const stream = fakeStream();
    const audioEl = fakeAudio();
    const changes = [];
    const session = new OutboundSession({
        leadId: 'lead-1',
        api,
        stream,
        env: { RTCPeerConnection: FakePeerConnection, iceServers: [], ...envOverrides },
        audioEl,
        onChange: () => changes.push(session.phase),
    });

    return { session, api, stream, audioEl, changes };
}

/**
 * Waits until OutboundSession has reached the point, inside applyAnswer(),
 * where it is blocked on the real ICE connection — i.e. setRemoteDescription
 * has already been applied to the peer connection. Used instead of a phase
 * assertion: the local phase deliberately stays RINGING through this whole
 * window (see outbound-media.js's own docblock), so it is no longer a valid
 * synchronization point on its own.
 */
async function waitForRemoteDescriptionApplied(pc) {
    await vi.waitFor(() => expect(pc.log.some((entry) => entry[0] === 'setRemoteDescription')).toBe(true));
}

beforeEach(() => {
    vi.useFakeTimers();
    FakePeerConnection.instances = [];
    FakeAudioContext.instances = [];
});

afterEach(() => {
    vi.useRealTimers();
});

describe('initiate()', () => {
    it('creates an offer, sets it locally, and sends it to Laravel — never directly to Meta', async () => {
        const { session, api } = setup();

        await session.initiate();

        const pc = FakePeerConnection.instances.at(-1);
        // The microphone track is added BEFORE createOffer() — WebRTC needs
        // it there for the offer's SDP to include the audio m-line at all.
        expect(pc.log.map((entry) => entry[0])).toEqual(['addTrack', 'createOffer', 'setLocalDescription']);
        expect(api.initiateOutbound).toHaveBeenCalledWith('lead-1', OFFER_SDP);
    });

    it('adds the microphone track disabled — no audio may flow before the call connects', async () => {
        const { session } = setup();

        await session.initiate();

        const pc = FakePeerConnection.instances.at(-1);
        expect(pc.log.find((entry) => entry[0] === 'addTrack')[1]).toBe(false);
    });

    it('moves to RINGING with the returned call id on success', async () => {
        const { session } = setup({ initiateOutbound: vi.fn(async () => ({ call: { id: 'oc-42', status: 'ringing' } })) });

        await session.initiate();

        expect(session.phase).toBe(OUTBOUND_PHASE.RINGING);
        expect(session.callId).toBe('oc-42');
    });

    it('fails cleanly when the server refuses the request (e.g. missing permission)', async () => {
        const { session } = setup({
            initiateOutbound: vi.fn(async () => {
                const error = new Error('This WhatsApp user has not granted permission for calls from this business.');
                error.code = 'permission_required';
                error.retryable = false;
                throw error;
            }),
        });

        await session.initiate();

        expect(session.phase).toBe(OUTBOUND_PHASE.FAILED);
        expect(session.error.code).toBe('permission_required');
    });

    it('fails cleanly when the server response carries no call id', async () => {
        const { session } = setup({ initiateOutbound: vi.fn(async () => ({ call: {} })) });

        await session.initiate();

        expect(session.phase).toBe(OUTBOUND_PHASE.FAILED);
        expect(session.error.code).toBe('no_call_id');
    });
});

describe('applyAnswer()', () => {
    async function ringing(apiOverrides = {}) {
        const made = setup(apiOverrides);
        await made.session.initiate();
        expect(made.session.phase).toBe(OUTBOUND_PHASE.RINGING);

        return made;
    }

    it('is a no-op while still INITIATING (no call id yet)', async () => {
        const { session, api } = setup();

        await session.applyAnswer();

        expect(session.phase).toBe(OUTBOUND_PHASE.INITIATING);
        expect(api.outboundAnswer).not.toHaveBeenCalled();
    });

    it('applies the SDP answer WITHOUT leaving RINGING or confirming until the real ICE connection is up', async () => {
        const { session, api } = await ringing();

        const applying = session.applyAnswer();
        await vi.waitFor(() => expect(api.outboundAnswer).toHaveBeenCalledWith('oc1'));
        const pc = FakePeerConnection.instances.at(-1);
        await waitForRemoteDescriptionApplied(pc);

        expect(pc.log.some((entry) => entry[0] === 'setRemoteDescription' && entry[1].type === 'answer')).toBe(true);
        expect(session.phase).toBe(OUTBOUND_PHASE.RINGING);
        expect(api.confirmOutboundConnected).not.toHaveBeenCalled();
        expect(session.connectedAt).toBeNull();

        pc.setState('connected');
        await applying;

        // The server-side Connected transition is confirmed only AFTER the
        // real WebRTC connection is up — never merely because Meta's SDP
        // answer arrived (see RecordOutboundCallEvent's own docblock).
        expect(api.confirmOutboundConnected).toHaveBeenCalledWith('oc1');
        expect(session.phase).toBe(OUTBOUND_PHASE.CONNECTED);
    });

    it('confirms the connection with the server only AFTER the real ICE connection, never before', async () => {
        const { session } = await ringing();
        const calls = [];

        const applying = session.applyAnswer();
        const pc = FakePeerConnection.instances.at(-1);
        await waitForRemoteDescriptionApplied(pc);

        session.api.confirmOutboundConnected.mockImplementationOnce(async (id) => {
            calls.push('confirm');

            return { call: { id, status: 'connected' } };
        });

        pc.setState('connected');
        calls.push('ice-connected');
        await applying;

        expect(calls).toEqual(['ice-connected', 'confirm']);
    });

    it('a lost race — the server refuses to confirm because the call already ended — fails cleanly, never locally declares Connected', async () => {
        const { session, audioEl } = await ringing({
            confirmOutboundConnected: vi.fn(async () => {
                const error = new Error('This call can no longer be confirmed as connected.');
                error.code = 'call_not_confirmable';
                error.retryable = false;
                throw error;
            }),
        });

        const applying = session.applyAnswer();
        const pc = FakePeerConnection.instances.at(-1);
        await waitForRemoteDescriptionApplied(pc);
        pc.setState('connected');
        await applying;

        expect(session.phase).toBe(OUTBOUND_PHASE.FAILED);
        expect(session.error.code).toBe('call_not_confirmable');
        expect(session.error.retryable).toBe(false);
        expect(audioEl.play).not.toHaveBeenCalled();
    });

    it('a second call is a no-op once the answer has already been applied', async () => {
        const { session, api } = await ringing();

        const applying = session.applyAnswer();
        const pc = FakePeerConnection.instances.at(-1);
        await waitForRemoteDescriptionApplied(pc);
        pc.setState('connected');
        await applying;

        await session.applyAnswer();

        expect(api.outboundAnswer).toHaveBeenCalledTimes(1);
    });

    it('fails cleanly when the answer is not yet available (retryable), and an explicit retry() then succeeds', async () => {
        let attempt = 0;
        const { session, api } = await ringing({
            outboundAnswer: vi.fn(async () => {
                attempt++;

                if (attempt === 1) {
                    const error = new Error('WhatsApp has not answered this call yet.');
                    error.code = 'answer_not_yet_available';
                    error.retryable = true;
                    throw error;
                }

                return { call: { id: 'oc1', status: 'answering' }, session: { sdp_type: 'answer', sdp: ANSWER_SDP } };
            }),
        });

        await session.applyAnswer();
        expect(session.phase).toBe(OUTBOUND_PHASE.FAILED);
        expect(session.error.retryable).toBe(true);

        // Mirrors AnswerSession's own Retry-button contract: an explicit
        // agent action, never an automatic silent re-attempt.
        const retrying = session.retry();
        await vi.waitFor(() => expect(api.outboundAnswer).toHaveBeenCalledTimes(2));
        const pc = FakePeerConnection.instances.at(-1);
        await waitForRemoteDescriptionApplied(pc);
        pc.setState('connected');
        await retrying;

        expect(session.phase).toBe(OUTBOUND_PHASE.CONNECTED);
        expect(api.outboundAnswer).toHaveBeenCalledTimes(2);
    });

    it('retry() does nothing when the prior failure was not marked retryable', async () => {
        const { session } = await ringing({
            outboundAnswer: vi.fn(async () => {
                const error = new Error('This call can no longer be answered.');
                error.code = 'answer_unavailable';
                error.retryable = false;
                throw error;
            }),
        });

        await session.applyAnswer();
        expect(session.phase).toBe(OUTBOUND_PHASE.FAILED);

        await session.retry();

        expect(session.phase).toBe(OUTBOUND_PHASE.FAILED);
    });

    it('times out and fails if the peer connection never reaches connected', async () => {
        const { session } = await ringing();

        const applying = session.applyAnswer();
        const pc = FakePeerConnection.instances.at(-1);
        await waitForRemoteDescriptionApplied(pc);
        await vi.advanceTimersByTimeAsync(20000);
        await applying;

        expect(session.phase).toBe(OUTBOUND_PHASE.FAILED);
        expect(session.error.code).toBe('ice_timeout');
    });
});

describe('mute / audio / teardown', () => {
    async function connected() {
        const made = setup();
        await made.session.initiate();
        const applying = made.session.applyAnswer();
        const pc = FakePeerConnection.instances.at(-1);
        await waitForRemoteDescriptionApplied(pc);
        pc.setState('connected');
        await applying;

        return made;
    }

    it('mute toggles the microphone track only once connected', async () => {
        const { session, stream } = await connected();

        session.setMuted(true);
        expect(stream.tracks[0].enabled).toBe(false);

        session.setMuted(false);
        expect(stream.tracks[0].enabled).toBe(true);
    });

    it('close() is idempotent and tears down the peer connection, microphone and audio', async () => {
        const { session, stream, audioEl } = await connected();
        const pc = FakePeerConnection.instances.at(-1);

        session.close();
        session.close();

        expect(pc.closed).toBe(true);
        expect(stream.tracks[0].stopped).toBe(true);
        expect(audioEl.srcObject).toBeNull();
        expect(session.phase).toBe(OUTBOUND_PHASE.CLOSED);
    });

    it('a lost ICE connection while Connected marks reconnecting, and a failure ends the session', async () => {
        const { session } = await connected();
        const pc = FakePeerConnection.instances.at(-1);

        pc.setState('disconnected');
        expect(session.reconnecting).toBe(true);

        pc.setState('failed');
        expect(session.phase).toBe(OUTBOUND_PHASE.FAILED);
        expect(session.error.code).toBe('connection_lost');
    });
});

describe('ringback', () => {
    it('starts exactly once on INITIATING → RINGING', async () => {
        const { session } = setup({}, { AudioContext: FakeAudioContext });

        await session.initiate();

        expect(session.phase).toBe(OUTBOUND_PHASE.RINGING);
        expect(FakeAudioContext.instances).toHaveLength(1);
        expect(FakeAudioContext.instances[0].lastOscillator.started).toBe(true);
        expect(session.ringback).not.toBeNull();
    });

    it('repeated cadence ticks reuse the same oscillator/context — never a second instance', async () => {
        const { session } = setup({}, { AudioContext: FakeAudioContext });

        await session.initiate();
        await vi.advanceTimersByTimeAsync(3000 * 5);

        expect(FakeAudioContext.instances).toHaveLength(1);
    });

    it('never starts while still INITIATING, and never starts at all if the call fails before RINGING', async () => {
        const { session } = setup({ initiateOutbound: vi.fn(async () => { throw Object.assign(new Error('nope'), { code: 'meta_rejected', retryable: false }); }) }, { AudioContext: FakeAudioContext });

        await session.initiate();

        expect(session.phase).toBe(OUTBOUND_PHASE.FAILED);
        expect(FakeAudioContext.instances).toHaveLength(0);
        expect(session.ringback).toBeNull();
    });

    it('Meta answering (the SDP answer / server status Answering) does NOT stop ringback by itself — only the real ICE connection completing does', async () => {
        const { session } = setup({}, { AudioContext: FakeAudioContext });
        await session.initiate();
        const context = FakeAudioContext.instances[0];

        const applying = session.applyAnswer();
        const pc = FakePeerConnection.instances.at(-1);
        await waitForRemoteDescriptionApplied(pc);

        expect(session.phase).toBe(OUTBOUND_PHASE.RINGING);
        expect(session.ringback).not.toBeNull();
        expect(context.lastOscillator.stopped).toBe(false);
        expect(context.closed).toBe(false);

        pc.setState('connected');
        await applying;

        expect(session.phase).toBe(OUTBOUND_PHASE.CONNECTED);
        expect(session.ringback).toBeNull();
        expect(context.lastOscillator.stopped).toBe(true);
        expect(context.closed).toBe(true);
    });

    it('RINGING → FAILED stops ringback', async () => {
        const { session } = setup(
            { outboundAnswer: vi.fn(async () => { throw Object.assign(new Error('gone'), { code: 'call_not_answerable', retryable: false }); }) },
            { AudioContext: FakeAudioContext },
        );
        await session.initiate();

        await session.applyAnswer();

        expect(session.phase).toBe(OUTBOUND_PHASE.FAILED);
        expect(session.ringback).toBeNull();
        expect(FakeAudioContext.instances[0].lastOscillator.stopped).toBe(true);
    });

    it('close() while still RINGING stops ringback (the agent-cancel path, which never goes through setPhase())', async () => {
        const { session } = setup({}, { AudioContext: FakeAudioContext });
        await session.initiate();

        session.close();

        expect(session.ringback).toBeNull();
        expect(FakeAudioContext.instances[0].lastOscillator.stopped).toBe(true);
        expect(FakeAudioContext.instances[0].closed).toBe(true);
    });

    it('close() after ringback already stopped (via setPhase()) is a safe no-op — no double-stop error', async () => {
        const { session } = setup({}, { AudioContext: FakeAudioContext });
        await session.initiate();
        const applying = session.applyAnswer();
        const pc = FakePeerConnection.instances.at(-1);
        await waitForRemoteDescriptionApplied(pc);
        pc.setState('connected');
        await applying;

        expect(() => session.close()).not.toThrow();
        expect(() => session.close()).not.toThrow();
    });

    it('AudioContext construction failure never throws into the call lifecycle — the call proceeds normally', async () => {
        const { session, api } = setup({}, { AudioContext: ThrowingAudioContext });

        await expect(session.initiate()).resolves.toBeUndefined();

        expect(session.phase).toBe(OUTBOUND_PHASE.RINGING);
        expect(session.ringback).toBeNull();
        expect(api.initiateOutbound).toHaveBeenCalled();
    });

    it('no AudioContext wired at all (the default test setup, and any unsupported browser) silently never starts ringback', async () => {
        const { session } = setup();

        await session.initiate();

        expect(session.phase).toBe(OUTBOUND_PHASE.RINGING);
        expect(session.ringback).toBeNull();
    });

    it('the cadence timer is fully cleared when ringback stops — no interval keeps firing', async () => {
        const { session } = setup({}, { AudioContext: FakeAudioContext });
        await session.initiate();
        const context = FakeAudioContext.instances[0];
        const setValueCallsBeforeStop = context.lastGain.gain.setValueAtTime.mock.calls.length;

        session.close();
        await vi.advanceTimersByTimeAsync(3000 * 5);

        expect(context.lastGain.gain.setValueAtTime.mock.calls.length).toBe(setValueCallsBeforeStop);
    });

    it('AnswerSession (inbound) never creates or exposes any ringback state', () => {
        const session = new AnswerSession({
            callId: 'call-1',
            api: fakeApi(),
            stream: fakeStream(),
            env: { RTCPeerConnection: FakePeerConnection, iceServers: [], AudioContext: FakeAudioContext },
            audioEl: fakeAudio(),
        });

        expect(session.ringback).toBeUndefined();
        expect(typeof session.startRingback).toBe('undefined');
        expect(typeof session.stopRingback).toBe('undefined');
        expect(FakeAudioContext.instances).toHaveLength(0);
    });
});
