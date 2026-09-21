import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from './api.js';
import { MODE, SoftphoneController } from './controller.js';
import { FakePeerConnection, fakeApi, fakeAudio, fakeStream, rawCall } from './test-helpers.js';

let controllers = [];

function setup({ calls = [], mediaOverrides = {} } = {}) {
    const state = { calls };
    const api = fakeApi({ active: vi.fn(async () => state.calls) });
    const handlers = {};
    const subscribe = vi.fn(({ onEvent, onReconnect }) => {
        handlers.onEvent = onEvent;
        handlers.onReconnect = onReconnect;

        return vi.fn();
    });
    const others = new Set();
    const listeners = new Set();
    const ownership = {
        others,
        otherOwnedCallIds: () => others,
        claim: vi.fn(),
        release: vi.fn(),
        onChange: (listener) => { listeners.add(listener); return () => listeners.delete(listener); },
        changed: () => listeners.forEach((listener) => listener()),
    };
    const stream = fakeStream();
    const audioEl = fakeAudio();
    const media = { supported: true, getMicrophone: vi.fn(async () => stream), env: { RTCPeerConnection: FakePeerConnection }, audioEl, ...mediaOverrides };
    const controller = new SoftphoneController({ api, createMedia: () => media, ownership, subscribe, pollMs: 30000 });
    controllers.push(controller);

    return { controller, api, state, handlers, subscribe, ownership, media, stream, audioEl, cards: () => controller.snapshot().cards };
}

const flush = () => vi.advanceTimersByTimeAsync(0);

function setVisibility(value) {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => value });
    document.dispatchEvent(new Event('visibilitychange'));
}

beforeEach(() => {
    vi.useFakeTimers();
    FakePeerConnection.instances = [];
    setVisibility('visible');
});

afterEach(() => {
    controllers.forEach((controller) => controller.stop());
    controllers = [];
    vi.useRealTimers();
});

describe('reconciliation with the server', () => {
    it('loads the active calls on mount and shows a ringing card with its countdown', async () => {
        const { controller, api, cards } = setup({ calls: [rawCall({ expires_in_seconds: 45 })] });

        controller.start();
        await flush();

        expect(api.active).toHaveBeenCalledTimes(1);
        expect(cards()).toHaveLength(1);
        expect(cards()[0]).toMatchObject({ id: 'c1', mode: MODE.RINGING, remainingSeconds: 45, canAnswer: true, canDecline: true, canDismiss: true });

        await vi.advanceTimersByTimeAsync(10000);
        expect(cards()[0].remainingSeconds).toBe(35);
    });

    it('hides a ringing card whose window has passed without touching the server', async () => {
        const { controller, api, cards } = setup({ calls: [rawCall({ expires_in_seconds: 3 })] });
        controller.start();
        await flush();

        await vi.advanceTimersByTimeAsync(4000);

        expect(cards()).toHaveLength(0);
        expect(api.reject).not.toHaveBeenCalled();
    });

    it('treats a realtime event as a nudge: it refetches and never trusts the payload as state', async () => {
        const { controller, handlers, state, api, cards } = setup({ calls: [rawCall()] });
        controller.start();
        await flush();

        // The event claims "connected", but the server still says ringing.
        handlers.onEvent({ id: 'c1', status: 'connected' });
        await flush();

        expect(api.active).toHaveBeenCalledTimes(2);
        expect(cards()[0].mode).toBe(MODE.RINGING);

        state.calls = [];
        handlers.onEvent({ id: 'c1', status: 'rejected' });
        await flush();

        expect(cards()).toHaveLength(0);
    });

    it('serializes overlapping refetches: a burst of duplicate events never runs two at once', async () => {
        let inFlight = 0;
        let maxInFlight = 0;
        const resolvers = [];
        const { controller, handlers, api } = setup();
        api.active.mockImplementation(() => new Promise((resolve) => {
            inFlight++;
            maxInFlight = Math.max(maxInFlight, inFlight);
            resolvers.push(() => { inFlight--; resolve([rawCall()]); });
        }));

        controller.start();
        for (let i = 0; i < 6; i++) {
            handlers.onEvent({ id: 'c1', status: 'ringing' });
        }
        await flush();
        expect(resolvers).toHaveLength(1);

        resolvers[0]();
        await flush();
        expect(resolvers).toHaveLength(2);

        resolvers[1]();
        await flush();

        expect(maxInFlight).toBe(1);
        expect(api.active).toHaveBeenCalledTimes(2);
    });

    it('out-of-order events end on the latest server answer', async () => {
        const { controller, handlers, state, cards } = setup({ calls: [rawCall()] });
        controller.start();
        await flush();

        // "Answering" event arrives after "rejected" was already the truth.
        state.calls = [];
        handlers.onEvent({ id: 'c1', status: 'rejected' });
        handlers.onEvent({ id: 'c1', status: 'answering' });
        await flush();

        expect(cards()).toHaveLength(0);
    });

    it('a failed read keeps what is on screen and recovers on the next nudge', async () => {
        const { controller, handlers, api, cards } = setup({ calls: [rawCall()] });
        controller.start();
        await flush();

        api.active.mockRejectedValueOnce(new ApiError({ status: 0, code: 'network', message: 'x', retryable: true }));
        handlers.onEvent({});
        await flush();
        expect(cards()).toHaveLength(1);

        api.active.mockResolvedValueOnce([]);
        handlers.onEvent({});
        await flush();
        expect(cards()).toHaveLength(0);
    });

    it('a Reverb reconnect refetches', async () => {
        const { controller, handlers, api } = setup();
        controller.start();
        await flush();

        handlers.onReconnect();
        await flush();

        expect(api.active).toHaveBeenCalledTimes(2);
    });
});

describe('safety polling', () => {
    it('polls about every 30 seconds while the tab is visible', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();
        expect(api.active).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(30000);
        expect(api.active).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(60000);
        expect(api.active).toHaveBeenCalledTimes(4);
    });

    it('pauses while hidden and resumes with an immediate refetch when visible again', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();

        setVisibility('hidden');
        await vi.advanceTimersByTimeAsync(120000);
        expect(api.active).toHaveBeenCalledTimes(1);

        setVisibility('visible');
        await flush();
        expect(api.active).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(30000);
        expect(api.active).toHaveBeenCalledTimes(3);
    });

    it('does not poll or refetch while offline, and catches up when back online', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();

        window.dispatchEvent(new Event('offline'));
        expect(controller.snapshot().offline).toBe(true);
        await vi.advanceTimersByTimeAsync(90000);
        expect(api.active).toHaveBeenCalledTimes(1);

        window.dispatchEvent(new Event('online'));
        await flush();
        expect(controller.snapshot().offline).toBe(false);
        expect(api.active).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(30000);
        expect(api.active).toHaveBeenCalledTimes(3);
    });

    it('never starts a second timer and stops everything on stop()', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();
        setVisibility('hidden');
        setVisibility('visible');
        setVisibility('visible');
        await flush();
        const afterVisible = api.active.mock.calls.length;

        await vi.advanceTimersByTimeAsync(30000);
        expect(api.active.mock.calls.length).toBe(afterVisible + 1);

        controller.stop();
        await vi.advanceTimersByTimeAsync(120000);
        expect(api.active.mock.calls.length).toBe(afterVisible + 1);
    });
});

describe('ringing cards', () => {
    it('shows several ringing cards at once', async () => {
        const { controller, cards } = setup({ calls: [rawCall({ id: 'a' }), rawCall({ id: 'b' }), rawCall({ id: 'c' })] });
        controller.start();
        await flush();

        expect(cards().map((card) => card.id)).toEqual(['a', 'b', 'c']);
        expect(cards().every((card) => card.canAnswer)).toBe(true);
    });

    it('dismiss hides the card in this browser only: no request, no state change', async () => {
        const { controller, api, cards, handlers } = setup({ calls: [rawCall({ id: 'a' }), rawCall({ id: 'b' })] });
        controller.start();
        await flush();

        controller.dismiss('a');

        expect(cards().map((card) => card.id)).toEqual(['b']);
        expect(api.reject).not.toHaveBeenCalled();
        expect(api.claim).not.toHaveBeenCalled();

        handlers.onEvent({});
        await flush();
        expect(cards().map((card) => card.id)).toEqual(['b']);
    });

    it('a dismissed call comes back on a fresh page (memory only), and is forgotten once it ends', async () => {
        const first = setup({ calls: [rawCall()] });
        first.controller.start();
        await flush();
        first.controller.dismiss('c1');
        expect(first.cards()).toHaveLength(0);

        const reloaded = setup({ calls: [rawCall()] });
        reloaded.controller.start();
        await flush();
        expect(reloaded.cards()).toHaveLength(1);

        first.state.calls = [];
        first.handlers.onEvent({});
        await flush();
        first.state.calls = [rawCall()];
        first.handlers.onEvent({});
        await flush();
        expect(first.cards()).toHaveLength(1);
    });
});

describe('declining', () => {
    it('declines through the reject endpoint once, ignoring duplicate clicks', async () => {
        const { controller, api, state, cards } = setup({ calls: [rawCall()] });
        controller.start();
        await flush();
        state.calls = [];

        controller.decline('c1');
        controller.decline('c1');
        await flush();

        expect(api.reject).toHaveBeenCalledTimes(1);
        expect(api.reject).toHaveBeenCalledWith('c1');
        expect(cards()).toHaveLength(0);
        expect(controller.snapshot().notices.map((notice) => notice.text)).toContain('Call declined.');
    });

    it('tells the agent when WhatsApp could not confirm the decline', async () => {
        const { controller, api } = setup({ calls: [rawCall()] });
        api.reject.mockResolvedValueOnce({ call: {}, meta_confirmed: false });
        controller.start();
        await flush();

        await controller.decline('c1');

        expect(controller.snapshot().notices.map((notice) => notice.text)).toContain('Declined here — WhatsApp may take a moment to stop ringing.');
    });

    it('a conflict on decline just refetches; it never breaks the view', async () => {
        const { controller, api, state, cards } = setup({ calls: [rawCall()] });
        api.reject.mockRejectedValueOnce(new ApiError({ status: 409, code: 'call_not_rejectable', message: 'x', retryable: false }));
        controller.start();
        await flush();
        state.calls = [];

        await controller.decline('c1');
        await flush();

        expect(cards()).toHaveLength(0);
        expect(api.active).toHaveBeenCalledTimes(2);
    });

    it('shows other decline errors to the agent', async () => {
        const { controller, api } = setup({ calls: [rawCall()] });
        api.reject.mockRejectedValueOnce(new ApiError({ status: 0, code: 'network', message: 'Could not reach the server.', retryable: true }));
        controller.start();
        await flush();

        await controller.decline('c1');

        expect(controller.snapshot().notices.map((notice) => notice.text)).toContain('Could not reach the server.');
    });
});

describe('answering', () => {
    async function answering(options) {
        const made = setup({ calls: [rawCall({ id: 'a' }), rawCall({ id: 'b' })], ...options });
        made.controller.start();
        await flush();
        made.state.calls = [rawCall({ id: 'a', status: 'answering', claimed_by_me: true }), rawCall({ id: 'b' })];

        return made;
    }

    it('asks for the microphone first, from the click, and only then claims', async () => {
        const { controller, api, media } = await answering();
        const order = [];
        media.getMicrophone.mockImplementation(async () => { order.push('microphone'); return fakeStream(); });
        api.claim.mockImplementation(async () => { order.push('claim'); return { session: { sdp: 'v=0\r\nm=audio 9 RTP/AVP 0\r\n' } }; });

        controller.answer('a');
        await flush();

        expect(order).toEqual(['microphone', 'claim']);
    });

    it('a denied microphone claims nothing and leaves the call ringing', async () => {
        const { controller, api, media, cards } = await answering();
        media.getMicrophone.mockRejectedValueOnce(new DOMException('denied', 'NotAllowedError'));

        await controller.answer('a');

        expect(api.claim).not.toHaveBeenCalled();
        expect(cards().find((card) => card.id === 'a').mode).toBe(MODE.RINGING);
        expect(controller.snapshot().notices.map((notice) => notice.text)).toContain('Microphone access is needed to answer calls.');
    });

    it('a browser that cannot take calls says so and claims nothing', async () => {
        const { controller, api } = await answering({ mediaOverrides: { supported: false } });

        await controller.answer('a');

        expect(api.claim).not.toHaveBeenCalled();
        expect(controller.snapshot().notices[0].kind).toBe('error');
    });

    it('ignores duplicate Answer clicks while the first is in flight', async () => {
        const { controller, api, media } = await answering();
        let release;
        media.getMicrophone.mockImplementation(() => new Promise((resolve) => { release = resolve; }));

        controller.answer('a');
        controller.answer('a');
        controller.answer('a');
        release(fakeStream());
        await flush();

        expect(media.getMicrophone).toHaveBeenCalledTimes(1);
        expect(api.claim).toHaveBeenCalledTimes(1);
    });

    it('only one media call at a time: other ringing cards cannot be answered but can be declined or dismissed', async () => {
        const { controller, api, media, cards } = await answering();

        controller.answer('a');
        await flush();
        await controller.refresh();

        const other = cards().find((card) => card.id === 'b');
        expect(other).toMatchObject({ mode: MODE.RINGING, canAnswer: false, canDecline: true, canDismiss: true });
        expect(controller.snapshot().mediaBusy).toBe(true);

        // A second Answer neither prompts for the microphone again nor claims.
        await controller.answer('b');
        expect(media.getMicrophone).toHaveBeenCalledTimes(1);
        expect(api.claim).toHaveBeenCalledTimes(1);
    });

    it('announces ownership while it owns the call and releases it when the call ends', async () => {
        const { controller, ownership, state, handlers } = await answering();

        controller.answer('a');
        await flush();
        expect(ownership.claim).toHaveBeenCalledWith('a');

        state.calls = [];
        handlers.onEvent({ id: 'a', status: 'completed' });
        await flush();

        expect(ownership.release).toHaveBeenCalled();
        expect(controller.session).toBeNull();
    });

    it('cleans up peer connection, microphone and audio when the server reports the call over', async () => {
        const { controller, stream, audioEl, state, handlers } = await answering();

        controller.answer('a');
        await flush();
        const connection = FakePeerConnection.instances.at(-1);

        state.calls = [];
        handlers.onEvent({});
        await flush();

        expect(connection.closed).toBe(true);
        expect(stream.tracks[0].stopped).toBe(true);
        expect(audioEl.srcObject).toBeNull();
        expect(controller.snapshot().notices.map((notice) => notice.text)).toContain('The call ended.');
    });

    it('a claim conflict ends the attempt as "handled elsewhere" and refetches', async () => {
        const { controller, api, ownership } = await answering();
        api.claim.mockRejectedValueOnce(new ApiError({ status: 409, code: 'already_claimed', message: 'x', retryable: false }));

        await controller.answer('a');
        await flush();

        expect(controller.session).toBeNull();
        expect(ownership.release).toHaveBeenCalled();
        expect(controller.snapshot().notices.map((notice) => notice.text)).toContain('This call is being handled elsewhere.');
        expect(api.active.mock.calls.length).toBeGreaterThan(1);
    });

    it('a retryable failure stays on the card with a Retry, and can then be retried', async () => {
        const { controller, api, cards } = await answering();
        api.answer.mockRejectedValueOnce(new ApiError({ status: 503, code: 'meta_unavailable', message: 'WhatsApp did not confirm the request. Try again.', retryable: true }));

        await controller.answer('a');

        expect(cards().find((card) => card.id === 'a')).toMatchObject({ mode: MODE.FAILED, retryable: true, error: 'WhatsApp did not confirm the request. Try again.', canDecline: true });

        const retrying = controller.retry();
        await vi.waitFor(() => expect(controller.session.phase).toBe('connecting'));
        FakePeerConnection.instances.at(-1).setState('connected');
        await retrying;

        expect(cards().find((card) => card.id === 'a').mode).toBe(MODE.CONNECTED);
    });

    it('a permanent failure keeps the card as an error so the agent can decline', async () => {
        const { controller, api, cards } = await answering();
        api.answer.mockRejectedValueOnce(new ApiError({ status: 502, code: 'meta_rejected', message: 'WhatsApp could not connect this call.', retryable: false }));

        await controller.answer('a');

        expect(cards().find((card) => card.id === 'a')).toMatchObject({ mode: MODE.FAILED, retryable: false, canDecline: true });
    });

    it('declining the call being answered cleans the session up', async () => {
        const { controller, api, stream, state } = await answering();
        controller.answer('a');
        await flush();
        state.calls = [];

        await controller.decline('a');

        expect(api.reject).toHaveBeenCalledWith('a');
        expect(controller.session).toBeNull();
        expect(stream.tracks[0].stopped).toBe(true);
    });
});

describe('a connected call', () => {
    async function connected() {
        const made = setup({ calls: [rawCall({ id: 'a' })] });
        made.controller.start();
        await flush();
        made.state.calls = [rawCall({ id: 'a', status: 'connected', claimed_by_me: true, expires_in_seconds: null, connected_at: new Date().toISOString() })];

        made.controller.answer('a');
        await vi.waitFor(() => expect(made.controller.session?.phase).toBe('connecting'));
        FakePeerConnection.instances.at(-1).setState('connected');
        await vi.waitFor(() => expect(made.controller.session.phase).toBe('connected'));
        await made.controller.refresh();

        return made;
    }

    it('shows a connected card with a running timer and Mute, but no hangup', async () => {
        const { cards } = await connected();
        await vi.advanceTimersByTimeAsync(7000);

        expect(cards()[0]).toMatchObject({ mode: MODE.CONNECTED, canDecline: false, canAnswer: false });
        expect(cards()[0].elapsedSeconds).toBeGreaterThanOrEqual(7);
    });

    it('mute toggles the microphone and sends nothing to the server', async () => {
        const { controller, api, stream, cards } = await connected();
        const requests = () => ['claim', 'answer', 'accept', 'reject'].map((name) => api[name].mock.calls.length);
        const before = requests();

        controller.toggleMute();
        expect(stream.tracks[0].enabled).toBe(false);
        expect(cards()[0].muted).toBe(true);

        controller.toggleMute();
        expect(stream.tracks[0].enabled).toBe(true);
        expect(requests()).toEqual(before);
    });

    it('mute does nothing before the call is connected', async () => {
        const { controller } = setup({ calls: [rawCall()] });
        controller.start();
        await flush();

        expect(() => controller.toggleMute()).not.toThrow();
    });
});

describe('reload, second tab and stale state', () => {
    it('after a reload an answering call is shown read-only and can only be declined', async () => {
        const { controller, cards } = setup({ calls: [rawCall({ id: 'a', status: 'answering', claimed_by_me: true })] });
        controller.start();
        await flush();

        expect(cards()[0]).toMatchObject({ mode: MODE.LOST, canAnswer: false, canDecline: true });
    });

    it('after a reload a connected call is read-only with no controls at all', async () => {
        const { controller, cards } = setup({ calls: [rawCall({ id: 'a', status: 'connected', claimed_by_me: true, expires_in_seconds: null, connected_at: new Date().toISOString() })] });
        controller.start();
        await flush();

        expect(cards()[0]).toMatchObject({ mode: MODE.LOST, canAnswer: false, canDecline: false, canDismiss: false });
    });

    it('never tries to reuse a stale SDP: a lost call is not answerable', async () => {
        const { controller, api } = setup({ calls: [rawCall({ id: 'a', status: 'answering', claimed_by_me: true })] });
        controller.start();
        await flush();

        await controller.answer('a');

        expect(api.claim).not.toHaveBeenCalled();
    });

    it('a call another tab owns is shown as active elsewhere and is not answerable here', async () => {
        const { controller, ownership, cards } = setup({ calls: [rawCall({ id: 'a', status: 'answering', claimed_by_me: true }), rawCall({ id: 'b' })] });
        ownership.others.add('a');
        controller.start();
        await flush();

        expect(cards().find((card) => card.id === 'a')).toMatchObject({ mode: MODE.OTHER_TAB, canDecline: false });
        expect(cards().find((card) => card.id === 'b')).toMatchObject({ mode: MODE.RINGING, canAnswer: false, canDecline: true });
    });

    it('re-renders when another tab claims or releases', async () => {
        const { controller, ownership, cards } = setup({ calls: [rawCall({ id: 'b' })] });
        controller.start();
        await flush();
        expect(cards()[0].canAnswer).toBe(true);

        ownership.others.add('x');
        const changes = vi.fn();
        controller.onChange = changes;
        ownership.changed();

        expect(changes).toHaveBeenCalled();
        expect(cards()[0].canAnswer).toBe(false);
    });

    it('a stale Answer click on a call that is gone does nothing', async () => {
        const { controller, api } = setup({ calls: [] });
        controller.start();
        await flush();

        await controller.answer('ghost');

        expect(api.claim).not.toHaveBeenCalled();
    });
});

describe('teardown', () => {
    it('stop() unsubscribes, removes its listeners and closes any live session', async () => {
        const { controller, subscribe, api } = setup({ calls: [rawCall({ id: 'a' })] });
        controller.start();
        await flush();
        controller.answer('a');
        await flush();
        const unsubscribe = subscribe.mock.results[0].value;

        controller.stop();
        setVisibility('hidden');
        setVisibility('visible');
        await flush();

        expect(unsubscribe).toHaveBeenCalled();
        expect(controller.session).toBeNull();
        expect(api.active).toHaveBeenCalledTimes(1);
    });
});
