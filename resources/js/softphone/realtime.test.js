import { describe, expect, it, vi } from 'vitest';
import { subscribeToCalls } from './realtime.js';

function fakeEcho(initialState = 'connected') {
    const bound = {};
    const channel = { listen: vi.fn(), stopListening: vi.fn() };

    return {
        channel,
        echo: {
            private: vi.fn(() => channel),
            join: vi.fn(() => ({})),
            leave: vi.fn(),
            connector: { pusher: { connection: { state: initialState, bind: vi.fn((name, fn) => { bound[name] = fn; }), unbind: vi.fn(), connect: vi.fn() } } },
        },
        emitState: (previous, current) => bound.state_change({ previous, current }),
    };
}

function fakeDoc(initialVisibility = 'visible') {
    const listeners = {};

    return {
        visibilityState: initialVisibility,
        addEventListener: vi.fn((name, fn) => { listeners[name] = fn; }),
        removeEventListener: vi.fn(),
        emitVisibilityChange: () => listeners.visibilitychange(),
    };
}

describe('softphone realtime', () => {
    it('listens for call.updated on the user\'s own private channel only', () => {
        const { echo, channel } = fakeEcho();
        const onEvent = vi.fn();

        subscribeToCalls({ echo, userId: 'u-1', onEvent, onReconnect: vi.fn(), doc: fakeDoc() });

        expect(echo.private).toHaveBeenCalledWith('tenant-user.u-1');
        expect(channel.listen).toHaveBeenCalledWith('.call.updated', onEvent);
    });

    it('asks for a refetch when Reverb reconnects, but not on the first connection', () => {
        const { echo, emitState } = fakeEcho('connecting');
        const onReconnect = vi.fn();
        subscribeToCalls({ echo, userId: 'u-1', onEvent: vi.fn(), onReconnect, doc: fakeDoc() });

        emitState('connecting', 'connected');
        expect(onReconnect).not.toHaveBeenCalled();

        emitState('connected', 'unavailable');
        emitState('unavailable', 'connected');
        expect(onReconnect).toHaveBeenCalledTimes(1);
    });

    it('treats a connection that was already up as connected before', () => {
        const { echo, emitState } = fakeEcho('connected');
        const onReconnect = vi.fn();
        subscribeToCalls({ echo, userId: 'u-1', onEvent: vi.fn(), onReconnect, doc: fakeDoc() });

        emitState('connecting', 'connected');

        expect(onReconnect).toHaveBeenCalledTimes(1);
    });

    it('stops only its own listener on teardown and never leaves the shared private channel', () => {
        const { echo, channel } = fakeEcho();
        const onEvent = vi.fn();
        const doc = fakeDoc();
        // No tenantId given here — the presence channel is opt-in (see the
        // dedicated presence tests below); this test is specifically about
        // the pre-existing shared tenant-user channel never being left.
        const stop = subscribeToCalls({ echo, userId: 'u-1', onEvent, onReconnect: vi.fn(), doc });

        stop();

        expect(channel.stopListening).toHaveBeenCalledWith('.call.updated', onEvent);
        expect(echo.connector.pusher.connection.unbind).toHaveBeenCalled();
        expect(echo.leave).not.toHaveBeenCalled();
        expect(doc.removeEventListener).toHaveBeenCalledWith('visibilitychange', expect.any(Function));
    });

    describe('WhatsApp Call Backup / Overflow Receiver — softphone presence channel', () => {
        it('joins the tenant softphone presence channel when a tenantId is given', () => {
            const { echo } = fakeEcho();

            subscribeToCalls({ echo, userId: 'u-1', tenantId: 't-1', onEvent: vi.fn(), onReconnect: vi.fn(), doc: fakeDoc() });

            expect(echo.join).toHaveBeenCalledWith('tenant-softphone.t-1');
        });

        it('never joins a presence channel when no tenantId is given', () => {
            const { echo } = fakeEcho();

            subscribeToCalls({ echo, userId: 'u-1', onEvent: vi.fn(), onReconnect: vi.fn(), doc: fakeDoc() });

            expect(echo.join).not.toHaveBeenCalled();
        });

        it('leaves the presence channel on teardown, and only the presence channel', () => {
            const { echo, channel } = fakeEcho();
            const doc = fakeDoc();
            const stop = subscribeToCalls({ echo, userId: 'u-1', tenantId: 't-1', onEvent: vi.fn(), onReconnect: vi.fn(), doc });

            stop();

            expect(echo.leave).toHaveBeenCalledTimes(1);
            expect(echo.leave).toHaveBeenCalledWith('tenant-softphone.t-1');
            // The shared private channel is stopped-listening-to, never left —
            // unchanged from the pre-existing behavior above.
            expect(channel.stopListening).toHaveBeenCalled();
        });

        it('never leaves anything on teardown when no tenantId was given (nothing was joined)', () => {
            const { echo } = fakeEcho();
            const stop = subscribeToCalls({ echo, userId: 'u-1', onEvent: vi.fn(), onReconnect: vi.fn(), doc: fakeDoc() });

            stop();

            expect(echo.leave).not.toHaveBeenCalled();
        });

        it('tolerates an Echo without a join/leave method (defensive, same discipline as the private-channel path)', () => {
            const channel = { listen: vi.fn(), stopListening: vi.fn() };

            expect(() => {
                const stop = subscribeToCalls({ echo: { private: () => channel }, userId: 'u-1', tenantId: 't-1', onEvent: vi.fn(), onReconnect: vi.fn(), doc: fakeDoc() });
                stop();
            }).not.toThrow();
        });
    });

    it('tolerates an Echo without a connector', () => {
        const channel = { listen: vi.fn() };

        expect(() => subscribeToCalls({ echo: { private: () => channel }, userId: 'u', onEvent: vi.fn(), onReconnect: vi.fn(), doc: fakeDoc() })()).not.toThrow();
    });

    describe('the connection nudge on visibility (a zombie connection recovery aid)', () => {
        it('nudges Pusher to reconnect when the tab becomes visible again', () => {
            const { echo } = fakeEcho('connected');
            const doc = fakeDoc();
            subscribeToCalls({ echo, userId: 'u-1', onEvent: vi.fn(), onReconnect: vi.fn(), doc });

            doc.emitVisibilityChange();

            expect(echo.connector.pusher.connection.connect).toHaveBeenCalledTimes(1);
        });

        it('does nothing when the tab becomes hidden', () => {
            const { echo } = fakeEcho('connected');
            const doc = fakeDoc('hidden');
            subscribeToCalls({ echo, userId: 'u-1', onEvent: vi.fn(), onReconnect: vi.fn(), doc });

            doc.emitVisibilityChange();

            expect(echo.connector.pusher.connection.connect).not.toHaveBeenCalled();
        });

        it('is a harmless no-op call regardless of the connection\'s actual health — Pusher itself decides whether anything needs to happen', () => {
            const { echo } = fakeEcho('unavailable');
            const doc = fakeDoc();

            expect(() => {
                subscribeToCalls({ echo, userId: 'u-1', onEvent: vi.fn(), onReconnect: vi.fn(), doc });
                doc.emitVisibilityChange();
            }).not.toThrow();
        });

        it('tolerates a connection with no connect() method (Echo without a connector)', () => {
            const channel = { listen: vi.fn() };
            const doc = fakeDoc();

            expect(() => {
                subscribeToCalls({ echo: { private: () => channel }, userId: 'u', onEvent: vi.fn(), onReconnect: vi.fn(), doc });
                doc.emitVisibilityChange();
            }).not.toThrow();
        });
    });
});
