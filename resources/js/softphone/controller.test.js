import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from './api.js';
import { MODE, POLL_MODE, SoftphoneController } from './controller.js';
import { OUTBOUND_PHASE } from './outbound-media.js';
import { FakePeerConnection, fakeApi, fakeAudio, fakeStream, rawCall } from './test-helpers.js';

let controllers = [];

// Mirrors the real production defaults (config/calling.php +
// components/softphone.blade.php): a 15s healthy-mode heartbeat, a 20s
// staleness threshold before escalating, and the pre-existing 5s
// degraded-mode retry rate.
function setup({ calls = [], mediaOverrides = {}, heartbeatMs = 15000, staleMs = 20000, pollMs = 5000 } = {}) {
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
    const media = { supported: true, getMicrophone: vi.fn(async () => stream), audioEl, ...mediaOverrides, env: { RTCPeerConnection: FakePeerConnection, ...mediaOverrides.env } };
    const controller = new SoftphoneController({ api, createMedia: () => media, ownership, subscribe, heartbeatMs, staleMs, pollMs });
    controllers.push(controller);

    return { controller, api, state, handlers, subscribe, ownership, media, stream, audioEl, cards: () => controller.snapshot().cards };
}

/**
 * Fails every `active()` call from now on with a retryable network error —
 * the shape used throughout this suite to simulate "even the HTTP path is
 * unreliable" and drive the controller from HEALTHY into DEGRADED.
 */
function failActiveCalls(api) {
    api.active.mockRejectedValue(new ApiError({ status: 0, code: 'network', message: 'Could not reach the server.', retryable: true }));
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

describe('adaptive realtime fallback (safety polling)', () => {
    it('polls at the healthy heartbeat interval (15s) by default, never the old fixed 30s rate', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();
        expect(api.active).toHaveBeenCalledTimes(1);
        expect(controller.mode).toBe(POLL_MODE.HEALTHY);

        await vi.advanceTimersByTimeAsync(15000);
        expect(api.active).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(15000);
        expect(api.active).toHaveBeenCalledTimes(3);
    });

    it('updates lastFreshAt on every successful refresh, whether poll- or event-triggered', async () => {
        const { controller, handlers } = setup();
        controller.start();
        await flush();
        const afterMount = controller.lastFreshAt;
        expect(afterMount).not.toBeNull();

        await vi.advanceTimersByTimeAsync(5000);
        handlers.onEvent({});
        await flush();
        expect(controller.lastFreshAt).toBeGreaterThan(afterMount);

        const afterEvent = controller.lastFreshAt;
        await vi.advanceTimersByTimeAsync(15000);
        expect(controller.lastFreshAt).toBeGreaterThan(afterEvent);
    });

    it('a realtime event landing does NOT by itself count as fresh — only the refresh it triggers succeeding does', async () => {
        const { controller, api, handlers } = setup();
        controller.start();
        await flush();
        const beforeFailedEvent = controller.lastFreshAt;

        await vi.advanceTimersByTimeAsync(5000);
        failActiveCalls(api);
        handlers.onEvent({}); // the event arrives, but the refresh it triggers fails
        await flush();

        // Unchanged: the event's mere arrival never counts, only a SUCCESSFUL
        // refresh reaching apply() does — and this one failed.
        expect(controller.lastFreshAt).toBe(beforeFailedEvent);
    });

    it('transitions to degraded only once staleMs (20s) has passed with zero successful refresh', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();
        expect(controller.mode).toBe(POLL_MODE.HEALTHY);

        failActiveCalls(api);

        await vi.advanceTimersByTimeAsync(15000); // first heartbeat tick fails, age=15s < staleMs=20s
        expect(controller.mode).toBe(POLL_MODE.HEALTHY);

        await vi.advanceTimersByTimeAsync(15000); // next tick at age=30s >= staleMs=20s
        expect(controller.mode).toBe(POLL_MODE.DEGRADED);
    });

    it('switches to the degraded (5s) retry interval immediately on crossing the staleness threshold, no gap', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();
        failActiveCalls(api);

        await vi.advanceTimersByTimeAsync(30000); // -> degraded, per the previous test's timeline
        expect(controller.mode).toBe(POLL_MODE.DEGRADED);

        api.active.mockResolvedValue([]);
        const before = api.active.mock.calls.length;
        await vi.advanceTimersByTimeAsync(5000); // degraded-mode tick
        expect(api.active.mock.calls.length).toBe(before + 1);
    });

    it('any successful refresh while degraded returns immediately to healthy and resumes the 15s heartbeat', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();
        failActiveCalls(api);

        await vi.advanceTimersByTimeAsync(30000);
        expect(controller.mode).toBe(POLL_MODE.DEGRADED);

        api.active.mockResolvedValue([]);
        await vi.advanceTimersByTimeAsync(5000); // the next degraded-mode tick succeeds
        expect(controller.mode).toBe(POLL_MODE.HEALTHY);

        const afterRecovery = api.active.mock.calls.length;
        await vi.advanceTimersByTimeAsync(5000); // would already have ticked again if still degraded
        expect(api.active.mock.calls.length).toBe(afterRecovery);

        await vi.advanceTimersByTimeAsync(10000); // total 15s since recovery -> one heartbeat tick
        expect(api.active.mock.calls.length).toBe(afterRecovery + 1);
    });

    it('a Reverb reconnect refetching while degraded also returns the controller to healthy', async () => {
        const { controller, api, handlers } = setup();
        controller.start();
        await flush();
        failActiveCalls(api);

        await vi.advanceTimersByTimeAsync(30000);
        expect(controller.mode).toBe(POLL_MODE.DEGRADED);

        api.active.mockResolvedValue([]);
        handlers.onReconnect();
        await flush();

        expect(controller.mode).toBe(POLL_MODE.HEALTHY);
    });

    it('discovers a brand-new ringing call within one heartbeat interval even with zero prior active calls and no realtime delivery at all — connection.state is never consulted', async () => {
        const { controller, api, state, cards } = setup({ calls: [] });
        controller.start();
        await flush();
        expect(cards()).toHaveLength(0);

        // The server-side call arrives (e.g. Meta's webhook landed, the row was
        // written) but is simulated as fully undelivered over realtime: no
        // handlers.onEvent, no handlers.onReconnect is ever called here. Only
        // the independent HTTP heartbeat can find it.
        state.calls = [rawCall({ id: 'zombie-call' })];

        await vi.advanceTimersByTimeAsync(15000);

        expect(cards().map((card) => card.id)).toEqual(['zombie-call']);
        // The HTTP path itself never failed, so mode never needed to escalate —
        // proving the healthy-mode heartbeat alone is what caught this, not a
        // fallback to degraded polling.
        expect(controller.mode).toBe(POLL_MODE.HEALTHY);
        expect(api.active).toHaveBeenCalledTimes(2);
    });

    it('a heartbeat tick and a realtime event landing together coalesce into one refresh (existing serialization holds under the new triggers)', async () => {
        const { controller, handlers, api } = setup();
        controller.start();
        await flush();

        let resolveTick;
        api.active.mockImplementation(() => new Promise((resolve) => { resolveTick = resolve; }));

        vi.advanceTimersByTime(15000); // the heartbeat tick fires and is now in flight
        handlers.onEvent({}); // arrives mid-flight: queued, not a second fetch
        await flush();

        expect(api.active).toHaveBeenCalledTimes(2); // mount + the in-flight tick only

        resolveTick([]);
        await flush();

        expect(api.active).toHaveBeenCalledTimes(3); // the queued re-run then executes once more

        resolveTick([]); // settle the trailing run too, so nothing is left in flight
        await flush();
    });

    it('a failed refresh never advances lastFreshAt and never throws, while degraded', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();
        failActiveCalls(api);

        await vi.advanceTimersByTimeAsync(30000);
        expect(controller.mode).toBe(POLL_MODE.DEGRADED);
        const freshAtWhileDegraded = controller.lastFreshAt;

        // Nothing here should throw; an unhandled error inside the timer
        // callback would make this await reject and fail the test on its own.
        await vi.advanceTimersByTimeAsync(5000);
        expect(controller.lastFreshAt).toBe(freshAtWhileDegraded);
        expect(controller.mode).toBe(POLL_MODE.DEGRADED);
    });

    it('pauses while hidden and resumes with an immediate refetch when visible again — no activity while hidden even past staleMs, and no burst on return', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();

        setVisibility('hidden');
        await vi.advanceTimersByTimeAsync(120000); // well past staleMs (20s) while hidden
        expect(api.active).toHaveBeenCalledTimes(1);
        expect(controller.mode).toBe(POLL_MODE.HEALTHY); // hidden time is never evidence of degradation

        setVisibility('visible');
        await flush();
        expect(api.active).toHaveBeenCalledTimes(2); // exactly one refetch, not a storm

        await vi.advanceTimersByTimeAsync(15000);
        expect(api.active).toHaveBeenCalledTimes(3);
    });

    it('does not poll or refetch while offline (regardless of mode), and catches up with one refresh when back online', async () => {
        const { controller, api } = setup();
        controller.start();
        await flush();
        failActiveCalls(api);
        await vi.advanceTimersByTimeAsync(30000);
        expect(controller.mode).toBe(POLL_MODE.DEGRADED);

        window.dispatchEvent(new Event('offline'));
        expect(controller.snapshot().offline).toBe(true);
        const beforeOffline = api.active.mock.calls.length;
        await vi.advanceTimersByTimeAsync(90000);
        expect(api.active.mock.calls.length).toBe(beforeOffline);
        expect(controller.mode).toBe(POLL_MODE.DEGRADED); // preserved, not reset, while offline

        api.active.mockResolvedValue([]);
        window.dispatchEvent(new Event('online'));
        await flush();
        expect(controller.snapshot().offline).toBe(false);
        expect(api.active.mock.calls.length).toBe(beforeOffline + 1);
        expect(controller.mode).toBe(POLL_MODE.HEALTHY); // the online-triggered refresh succeeded

        const afterOnline = api.active.mock.calls.length;
        await vi.advanceTimersByTimeAsync(15000);
        expect(api.active.mock.calls.length).toBe(afterOnline + 1);
    });

    it('two independent controller instances track freshness and mode independently', async () => {
        const a = setup();
        const b = setup();
        a.controller.start();
        b.controller.start();
        await flush();

        failActiveCalls(a.api);
        await vi.advanceTimersByTimeAsync(30000);

        expect(a.controller.mode).toBe(POLL_MODE.DEGRADED);
        expect(b.controller.mode).toBe(POLL_MODE.HEALTHY);
    });

    it('a call that ends while the controller is degraded is still reflected by the recovering refresh (terminal transition across the mode boundary)', async () => {
        const { controller, api, state, cards } = setup({ calls: [rawCall({ id: 'a' })] });
        controller.start();
        await flush();
        expect(cards()).toHaveLength(1);

        failActiveCalls(api);
        await vi.advanceTimersByTimeAsync(30000);
        expect(controller.mode).toBe(POLL_MODE.DEGRADED);

        state.calls = []; // the call ended server-side while we were degraded
        api.active.mockResolvedValue(state.calls);
        await vi.advanceTimersByTimeAsync(5000);

        expect(controller.mode).toBe(POLL_MODE.HEALTHY);
        expect(cards()).toHaveLength(0);
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

        await vi.advanceTimersByTimeAsync(15000);
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

describe('WhatsApp Call Backup / Overflow Receiver — assignee / receivingAs pass-through', () => {
    it('renders the server-provided assignee and receivingAs on a ringing card exactly as sent, never recomputed', async () => {
        const { controller, cards } = setup({
            calls: [rawCall({ assignee: { id: 'u-assignee', name: 'User A' }, receiving_as: 'backup' })],
        });
        controller.start();
        await flush();

        expect(cards()[0].assignee).toEqual({ id: 'u-assignee', name: 'User A' });
        expect(cards()[0].receivingAs).toBe('backup');
    });

    it('a normal assigned call carries assignee=null, receivingAs="assignee" — the fixture default, unchanged by this feature', async () => {
        const { controller, cards } = setup({ calls: [rawCall()] });
        controller.start();
        await flush();

        expect(cards()[0].assignee).toBeNull();
        expect(cards()[0].receivingAs).toBe('assignee');
    });

    it('admin_fallback and agent_initiated pass through unchanged, exactly as the server sends them', async () => {
        const { controller, cards } = setup({
            calls: [
                rawCall({ id: 'a', receiving_as: 'admin_fallback', assignee: { id: 'u1', name: 'Original Owner' } }),
                rawCall({ id: 'b', receiving_as: 'agent_initiated' }),
            ],
        });
        controller.start();
        await flush();

        expect(cards().find((card) => card.id === 'a')).toMatchObject({ receivingAs: 'admin_fallback', assignee: { id: 'u1', name: 'Original Owner' } });
        expect(cards().find((card) => card.id === 'b')).toMatchObject({ receivingAs: 'agent_initiated' });
    });

    it('survives into the claimed/answering (lost-on-reload) card unchanged, the same way lead does', async () => {
        const { controller, cards } = setup({
            calls: [rawCall({ id: 'a', status: 'answering', claimed_by_me: true, assignee: { id: 'u-assignee', name: 'User A' }, receiving_as: 'backup' })],
        });
        controller.start();
        await flush();

        expect(cards()[0]).toMatchObject({ mode: MODE.LOST, assignee: { id: 'u-assignee', name: 'User A' }, receivingAs: 'backup' });
    });

    it('survives into the connected (lost-on-reload) card unchanged', async () => {
        const { controller, cards } = setup({
            calls: [rawCall({ id: 'a', status: 'connected', claimed_by_me: true, expires_in_seconds: null, connected_at: new Date().toISOString(), assignee: { id: 'u-assignee', name: 'User A' }, receiving_as: 'backup' })],
        });
        controller.start();
        await flush();

        expect(cards()[0]).toMatchObject({ mode: MODE.LOST, assignee: { id: 'u-assignee', name: 'User A' }, receivingAs: 'backup' });
    });

    it('survives a realtime-triggered refresh with the same values (reconnect/self-healing does not drop or stale the context)', async () => {
        const { controller, cards, state, handlers } = setup({
            calls: [rawCall({ assignee: { id: 'u-assignee', name: 'User A' }, receiving_as: 'backup' })],
        });
        controller.start();
        await flush();
        expect(cards()[0].receivingAs).toBe('backup');

        state.calls = [rawCall({ assignee: { id: 'u-assignee', name: 'User A' }, receiving_as: 'backup' })];
        handlers.onEvent({});
        await flush();

        expect(cards()[0].assignee).toEqual({ id: 'u-assignee', name: 'User A' });
        expect(cards()[0].receivingAs).toBe('backup');
    });

    it('a missing/malformed assignee from the server never crashes and never synthesizes a fake user', async () => {
        const { controller, cards } = setup({ calls: [rawCall({ assignee: null, receiving_as: 'admin_fallback' })] });
        controller.start();
        await flush();

        expect(cards()[0].assignee).toBeNull();
        expect(cards()[0].receivingAs).toBe('admin_fallback');
    });

    it('the synthesized pending-outbound card (before any server row exists) is agent_initiated with no assignee yet', async () => {
        const { controller, api, cards } = setup({ calls: [] });
        api.initiateOutbound.mockImplementation(() => new Promise(() => {}));
        controller.start();
        await flush();

        controller.callOutbound('lead-1', { id: 'lead-1', name: 'Prospect', phone: '+15550001111' });
        await flush();

        expect(cards()[0]).toMatchObject({ assignee: null, receivingAs: 'agent_initiated', direction: 'outbound' });
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
    async function connected(mediaOverrides = {}) {
        const made = setup({ calls: [rawCall({ id: 'a' })], mediaOverrides });
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

    it('shows a connected card with a running timer, Mute and Hang up (Step 7a)', async () => {
        const { cards } = await connected();
        await vi.advanceTimersByTimeAsync(7000);

        expect(cards()[0]).toMatchObject({ mode: MODE.CONNECTED, canDecline: false, canAnswer: false, canHangup: true });
        expect(cards()[0].elapsedSeconds).toBeGreaterThanOrEqual(7);
    });

    it('the elapsed timer still runs even when the server\'s connected_at never reaches this tab', async () => {
        // Reproduces the real bug exactly: the poll/realtime round-trip that
        // would normally populate call.connectedAt from the server never
        // lands (a dropped realtime event, a slow/failed poll, a flaky mobile
        // network) — the timer must still tick from this tab's own locally
        // observed connect moment, not sit frozen on a `?? 0` fallback.
        const made = setup({ calls: [rawCall({ id: 'a' })] });
        made.controller.start();
        await flush();
        // connected_at stays null forever, unlike the connected() helper above.
        made.state.calls = [rawCall({ id: 'a', status: 'connected', claimed_by_me: true, expires_in_seconds: null, connected_at: null })];

        made.controller.answer('a');
        await vi.waitFor(() => expect(made.controller.session?.phase).toBe('connecting'));
        FakePeerConnection.instances.at(-1).setState('connected');
        await vi.waitFor(() => expect(made.controller.session.phase).toBe('connected'));
        await made.controller.refresh(); // server still reports connected_at: null

        await vi.advanceTimersByTimeAsync(5000);

        expect(made.cards()[0].mode).toBe(MODE.CONNECTED);
        expect(made.cards()[0].elapsedSeconds).toBeGreaterThanOrEqual(5);
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

    it('speaker toggle switches the audio output device and is reflected on the card', async () => {
        const mediaDevices = { enumerateDevices: vi.fn(async () => [
            { kind: 'audiooutput', label: 'Earpiece', deviceId: 'earpiece-1' },
            { kind: 'audiooutput', label: 'Speaker', deviceId: 'speaker-1' },
        ]) };
        const { controller, audioEl, cards } = await connected({ env: { mediaDevices } });
        audioEl.setSinkId = vi.fn(async () => undefined);
        await vi.waitFor(() => expect(cards()[0].speakerAvailable).toBe(true));

        await controller.toggleSpeaker();
        expect(cards()[0].speakerOn).toBe(true);
        expect(audioEl.setSinkId).toHaveBeenLastCalledWith('speaker-1');

        await controller.toggleSpeaker();
        expect(cards()[0].speakerOn).toBe(false);
        expect(audioEl.setSinkId).toHaveBeenLastCalledWith('earpiece-1');
    });

    it('speaker toggle is unavailable (hidden on the card) with no distinct speaker device', async () => {
        const { cards } = await connected();

        await flush();
        expect(cards()[0].speakerAvailable).toBe(false);
    });

    it('speaker toggle does nothing before the call is connected', async () => {
        const { controller } = setup({ calls: [rawCall()] });
        controller.start();
        await flush();

        expect(() => controller.toggleSpeaker()).not.toThrow();
    });

    it('hangs up through the hangup endpoint once, ignoring duplicate clicks, and tears the session down immediately', async () => {
        const { controller, api, state, stream, cards } = await connected();
        state.calls = [];

        controller.hangup('a');
        controller.hangup('a');
        await flush();

        expect(api.hangup).toHaveBeenCalledTimes(1);
        expect(api.hangup).toHaveBeenCalledWith('a');
        expect(controller.session).toBeNull();
        expect(stream.tracks[0].stopped).toBe(true);
        expect(cards()).toHaveLength(0);
        expect(controller.snapshot().notices.map((notice) => notice.text)).toContain('The call ended.');
    });

    it('a conflict on hangup (the call already ended some other way) still tears the session down quietly', async () => {
        const { controller, api, stream } = await connected();
        api.hangup.mockRejectedValueOnce(new ApiError({ status: 409, code: 'call_not_connected', message: 'x', retryable: false }));

        await controller.hangup('a');

        expect(controller.session).toBeNull();
        expect(stream.tracks[0].stopped).toBe(true);
    });

    it('shows other hangup errors to the agent without tearing the session down', async () => {
        const { controller, api } = await connected();
        api.hangup.mockRejectedValueOnce(new ApiError({ status: 0, code: 'network', message: 'Could not reach the server.', retryable: true }));

        await controller.hangup('a');

        expect(controller.session).not.toBeNull();
        expect(controller.snapshot().notices.map((notice) => notice.text)).toContain('Could not reach the server.');
    });

    it('does nothing for a call this tab does not own as a live session', async () => {
        const { controller, api } = setup({ calls: [rawCall({ status: 'answering', claimed_by_me: true })] });
        controller.start();
        await flush();

        controller.hangup('c1');
        await flush();

        expect(api.hangup).not.toHaveBeenCalled();
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

describe('primaryCallId (mobile full-screen)', () => {
    it('is null when there is no session and nothing ringing', async () => {
        const { controller } = setup({ calls: [] });
        controller.start();
        await flush();

        expect(controller.snapshot().primaryCallId).toBeNull();
    });

    it('is the first ringing call when nothing is being answered yet', async () => {
        const { controller } = setup({ calls: [rawCall({ id: 'a' }), rawCall({ id: 'b' })] });
        controller.start();
        await flush();

        expect(controller.snapshot().primaryCallId).toBe('a');
    });

    it('is this tab\'s own session once it owns one, even while another call is also ringing', async () => {
        const { controller } = setup({ calls: [rawCall({ id: 'a' }), rawCall({ id: 'b' })] });
        controller.start();
        await flush();

        controller.answer('a');
        await flush();

        expect(controller.snapshot().primaryCallId).toBe('a');
    });

    it('is null for a call this tab does not own as a live session (other-tab / lost), even though it is claimedByMe', async () => {
        const { controller } = setup({ calls: [rawCall({ status: 'answering', claimed_by_me: true })] });
        controller.start();
        await flush();

        expect(controller.snapshot().primaryCallId).toBeNull();
    });

    it('stays on the own session\'s id through connecting, connected and failed — only the template decides which of those get full-screen', async () => {
        const { controller, cards } = setup({ calls: [rawCall({ id: 'a' })] });
        controller.start();
        await flush();

        controller.answer('a');
        await vi.waitFor(() => expect(controller.session?.phase).toBe('connecting'));
        expect(controller.snapshot().primaryCallId).toBe('a');
        expect(cards().find((c) => c.id === 'a').mode).toBe(MODE.CONNECTING);
    });
});

describe('outbound calling', () => {
    it('callOutbound() asks for the microphone first, then sends the offer; once the server row is visible via refresh the card is served from it directly', async () => {
        const { controller, api, media, state } = setup({ calls: [] });
        controller.start();
        await flush();

        controller.callOutbound('lead-1');
        await flush();

        expect(media.getMicrophone).toHaveBeenCalledTimes(1);
        expect(api.initiateOutbound).toHaveBeenCalledWith('lead-1', expect.any(String));
        expect(controller.session).not.toBeNull();
        expect(controller.session.callId).toBe('oc1');

        state.calls = [rawCall({ id: 'oc1', status: 'ringing' })];
        await controller.refresh();

        const card = controller.snapshot().cards.find((c) => c.id === 'oc1');
        expect(card).toMatchObject({ direction: 'outbound', mode: MODE.CONNECTING, canCancel: true, canDecline: false, canAnswer: false });
    });

    it('the card appears instantly once the session exists — using the lead info passed to callOutbound() — before any server round trip completes', async () => {
        const { controller, api, media } = setup({ calls: [] });
        let releaseInitiate;
        api.initiateOutbound.mockImplementation(() => new Promise((resolve) => { releaseInitiate = resolve; }));
        controller.start();
        await flush();

        controller.callOutbound('lead-1', { id: 'lead-1', name: 'Ada Lovelace', phone: '+15551230000' });
        await flush();

        expect(media.getMicrophone).toHaveBeenCalledTimes(1);
        expect(controller.session).not.toBeNull();
        expect(controller.session.callId).toBeNull();

        // Still INITIATING — no call id, no server row could possibly exist
        // yet — and the card is already there, synthesized from pendingLead.
        const pending = controller.snapshot().cards.find((c) => c.lead.id === 'lead-1');
        expect(pending).toMatchObject({
            direction: 'outbound',
            mode: MODE.CONNECTING,
            phase: OUTBOUND_PHASE.INITIATING,
            lead: { id: 'lead-1', name: 'Ada Lovelace', phone: '+15551230000' },
            canCancel: true,
        });

        releaseInitiate({ call: { id: 'oc1', status: 'ringing' } });
        await flush();

        // A real call id now exists, but no refresh has happened yet — the
        // card is still synthesized, just re-keyed by the real call id, and
        // still shows the lead info from the click rather than nothing.
        const ringingPending = controller.snapshot().cards.find((c) => c.id === 'oc1');
        expect(ringingPending).toMatchObject({
            direction: 'outbound',
            mode: MODE.CONNECTING,
            phase: OUTBOUND_PHASE.RINGING,
            lead: { name: 'Ada Lovelace' },
            canCancel: true,
        });
    });

    it('without lead info (e.g. an older caller that never passes it), the pending card still renders using the bare lead id', async () => {
        const { controller, api } = setup({ calls: [] });
        let releaseInitiate;
        api.initiateOutbound.mockImplementation(() => new Promise((resolve) => { releaseInitiate = resolve; }));
        controller.start();
        await flush();

        controller.callOutbound('lead-9');
        await flush();

        const pending = controller.snapshot().cards.find((c) => c.lead.id === 'lead-9');
        expect(pending).toMatchObject({ direction: 'outbound', lead: { id: 'lead-9', name: null, phone: null } });

        releaseInitiate({ call: { id: 'oc9', status: 'ringing' } });
        await flush();
    });

    it('a second call attempt is blocked while one is already active (mediaBusy), same rule as inbound', async () => {
        const { controller, api, media } = setup({ calls: [] });
        controller.start();
        await flush();

        controller.callOutbound('lead-1');
        await flush();
        expect(api.initiateOutbound).toHaveBeenCalledTimes(1);

        await controller.callOutbound('lead-2');
        expect(api.initiateOutbound).toHaveBeenCalledTimes(1);
        expect(media.getMicrophone).toHaveBeenCalledTimes(1);
    });

    it('the "session ended" guard never fires while still INITIATING (no call id yet) — only AnswerSession ever has a callId from construction', async () => {
        const { controller, api, state } = setup({ calls: [] });
        let releaseInitiate;
        api.initiateOutbound.mockImplementation(() => new Promise((resolve) => { releaseInitiate = resolve; }));
        controller.start();
        await flush();

        controller.callOutbound('lead-1');
        await flush();
        expect(controller.session).not.toBeNull();
        expect(controller.session.callId).toBeNull();

        // A refresh lands while still INITIATING — must not tear the session down.
        state.calls = [];
        await controller.refresh();
        expect(controller.session).not.toBeNull();

        releaseInitiate({ call: { id: 'oc1', status: 'ringing' } });
        await flush();
        expect(controller.session.callId).toBe('oc1');
    });

    it('applyAnswer() is triggered automatically once a refresh reports the call as answering, and only reaches Connected after the real WebRTC connection is confirmed server-side', async () => {
        const { controller, api, state } = setup({ calls: [] });
        controller.start();
        await flush();

        controller.callOutbound('lead-1');
        await flush();
        expect(controller.session.phase).toBe(OUTBOUND_PHASE.RINGING);

        // Meta's SDP answer has arrived — the call is Answering, NOT
        // Connected. This alone must not move the session out of RINGING
        // (ringback keeps playing) or confirm anything.
        state.calls = [rawCall({ id: 'oc1', status: 'answering', claimed_by_me: true })];
        await controller.refresh();
        await flush();
        expect(controller.session.phase).toBe(OUTBOUND_PHASE.RINGING);
        expect(api.confirmOutboundConnected).not.toHaveBeenCalled();

        FakePeerConnection.instances.at(-1).setState('connected');
        await vi.waitFor(() => expect(controller.session.phase).toBe(OUTBOUND_PHASE.CONNECTED));
        expect(api.confirmOutboundConnected).toHaveBeenCalledWith('oc1');
    });

    it('canCancel becomes false once the server reports Answering, regardless of local WebRTC/acceptance progress — CancelOutboundLeadCall does not allow it past Ringing', async () => {
        const { controller, state } = setup({ calls: [] });
        controller.start();
        await flush();
        controller.callOutbound('lead-1');
        await flush();
        state.calls = [rawCall({ id: 'oc1', status: 'answering', claimed_by_me: true })];
        await controller.refresh();
        await flush();

        // Local phase is still RINGING at this point (see the test above) —
        // canCancel must key off the server's own status, not local phase.
        expect(controller.session.phase).toBe(OUTBOUND_PHASE.RINGING);
        const card = controller.snapshot().cards.find((c) => c.id === 'oc1');
        expect(card.canCancel).toBe(false);
    });

    it('cancelOutboundCall() tears the session down and calls the cancel endpoint once a call id is known', async () => {
        const { controller, api } = setup({ calls: [] });
        controller.start();
        await flush();

        controller.callOutbound('lead-1');
        await flush();

        await controller.cancelOutboundCall();

        expect(api.cancelOutbound).toHaveBeenCalledWith('oc1');
        expect(controller.session).toBeNull();
    });

    it('cancelOutboundCall() during the brief INITIATING window (no call id yet) tears down locally without any API call', async () => {
        const { controller, api } = setup({ calls: [] });
        let releaseInitiate;
        api.initiateOutbound.mockImplementation(() => new Promise((resolve) => { releaseInitiate = resolve; }));
        controller.start();
        await flush();

        controller.callOutbound('lead-1');
        await flush();
        expect(controller.session.callId).toBeNull();

        await controller.cancelOutboundCall();

        expect(controller.session).toBeNull();
        expect(api.cancelOutbound).not.toHaveBeenCalled();

        releaseInitiate({ call: { id: 'oc1', status: 'ringing' } });
    });

    it('cancelOutboundCall() does nothing once Connected — Hang Up (the existing, unchanged endpoint) is the only way to end it', async () => {
        const { controller, api, state } = setup({ calls: [] });
        controller.start();
        await flush();
        controller.callOutbound('lead-1');
        await flush();
        state.calls = [rawCall({ id: 'oc1', status: 'answering', claimed_by_me: true })];
        await controller.refresh();
        FakePeerConnection.instances.at(-1).setState('connected');
        await vi.waitFor(() => expect(controller.session.phase).toBe(OUTBOUND_PHASE.CONNECTED));

        await controller.cancelOutboundCall();

        expect(api.cancelOutbound).not.toHaveBeenCalled();
        expect(controller.session).not.toBeNull();
        expect(controller.snapshot().cards[0].canHangup).toBe(true);
    });

    describe('reload/lost outbound call safety (pre-real-Meta fix #2)', () => {
        it('an outbound call still Ringing after a reload (no local session) never renders as an answerable inbound ringing card', async () => {
            // "Reload" = a fresh controller with no session at all, whose
            // very first /calls/active response already contains this
            // tab's own outbound call, still Ringing, claimed_by_me true —
            // exactly what the server would report after a real reload.
            const { controller, cards } = setup({ calls: [rawCall({ id: 'oc1', status: 'ringing', claimed_by_me: true, direction: 'outbound' })] });
            controller.start();
            await flush();

            expect(controller.session).toBeNull();
            const card = cards().find((c) => c.id === 'oc1');
            expect(card.mode).not.toBe(MODE.RINGING);
            expect(card.mode).toBe(MODE.LOST);
            expect(card.canAnswer).toBe(false);
        });

        it('no inbound /claim request can be triggered even if answer() is called directly on the reloaded outbound call', async () => {
            const { controller, api } = setup({ calls: [rawCall({ id: 'oc1', status: 'ringing', claimed_by_me: true, direction: 'outbound' })] });
            controller.start();
            await flush();

            await controller.answer('oc1');

            expect(api.claim).not.toHaveBeenCalled();
            expect(controller.session).toBeNull();
        });

        it('the call remains exactly as the server reports it (Ringing) — this is a display-only fix, never a state mutation', async () => {
            const { controller, state } = setup({ calls: [rawCall({ id: 'oc1', status: 'ringing', claimed_by_me: true, direction: 'outbound' })] });
            controller.start();
            await flush();

            await controller.answer('oc1');
            await controller.refresh();

            // Still exactly what the fake server reports — nothing here
            // ever wrote to state.calls; only a real Meta terminate or the
            // stale-reconciliation sweep (both server-side, Phase 7) can
            // end it.
            expect(state.calls[0].status).toBe('ringing');
        });

        it('inbound Ringing behavior after a reload is completely unchanged: still answerable', async () => {
            const { controller, cards } = setup({ calls: [rawCall({ id: 'c1', status: 'ringing', claimed_by_me: false })] });
            controller.start();
            await flush();

            const card = cards().find((c) => c.id === 'c1');
            expect(card.mode).toBe(MODE.RINGING);
            expect(card.canAnswer).toBe(true);
        });

        it('a reloaded outbound call already Connected is also never answerable (existing "lost" behavior, unchanged)', async () => {
            const { controller, cards } = setup({ calls: [rawCall({ id: 'oc1', status: 'connected', claimed_by_me: true, direction: 'outbound', expires_in_seconds: null, connected_at: new Date().toISOString() })] });
            controller.start();
            await flush();

            const card = cards().find((c) => c.id === 'oc1');
            expect(card.mode).toBe(MODE.LOST);
            expect(card.canAnswer).toBe(false);
        });

        it('an outbound call in Answering after a reload never exposes the inbound-only decline/reject action (same class of bug as the Ringing case above)', async () => {
            const { controller, cards } = setup({ calls: [rawCall({ id: 'oc1', status: 'answering', claimed_by_me: true, direction: 'outbound' })] });
            controller.start();
            await flush();

            expect(controller.session).toBeNull();
            const card = cards().find((c) => c.id === 'oc1');
            expect(card.mode).toBe(MODE.LOST);
            expect(card.canAnswer).toBe(false);
            expect(card.canDecline).toBe(false);
        });

        it('inbound Answering after a reload is completely unchanged: still declinable', async () => {
            const { controller, cards } = setup({ calls: [rawCall({ id: 'c1', status: 'answering', claimed_by_me: true })] });
            controller.start();
            await flush();

            const card = cards().find((c) => c.id === 'c1');
            expect(card.mode).toBe(MODE.LOST);
            expect(card.canDecline).toBe(true);
        });
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
