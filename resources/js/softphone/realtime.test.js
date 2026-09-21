import { describe, expect, it, vi } from 'vitest';
import { subscribeToCalls } from './realtime.js';

function fakeEcho(initialState = 'connected') {
    const bound = {};
    const channel = { listen: vi.fn(), stopListening: vi.fn() };

    return {
        channel,
        echo: {
            private: vi.fn(() => channel),
            connector: { pusher: { connection: { state: initialState, bind: vi.fn((name, fn) => { bound[name] = fn; }), unbind: vi.fn() } } },
        },
        emitState: (previous, current) => bound.state_change({ previous, current }),
    };
}

describe('softphone realtime', () => {
    it('listens for call.updated on the user\'s own private channel only', () => {
        const { echo, channel } = fakeEcho();
        const onEvent = vi.fn();

        subscribeToCalls({ echo, userId: 'u-1', onEvent, onReconnect: vi.fn() });

        expect(echo.private).toHaveBeenCalledWith('tenant-user.u-1');
        expect(channel.listen).toHaveBeenCalledWith('.call.updated', onEvent);
    });

    it('asks for a refetch when Reverb reconnects, but not on the first connection', () => {
        const { echo, emitState } = fakeEcho('connecting');
        const onReconnect = vi.fn();
        subscribeToCalls({ echo, userId: 'u-1', onEvent: vi.fn(), onReconnect });

        emitState('connecting', 'connected');
        expect(onReconnect).not.toHaveBeenCalled();

        emitState('connected', 'unavailable');
        emitState('unavailable', 'connected');
        expect(onReconnect).toHaveBeenCalledTimes(1);
    });

    it('treats a connection that was already up as connected before', () => {
        const { echo, emitState } = fakeEcho('connected');
        const onReconnect = vi.fn();
        subscribeToCalls({ echo, userId: 'u-1', onEvent: vi.fn(), onReconnect });

        emitState('connecting', 'connected');

        expect(onReconnect).toHaveBeenCalledTimes(1);
    });

    it('stops only its own listener on teardown and never leaves the shared channel', () => {
        const { echo, channel } = fakeEcho();
        echo.leave = vi.fn();
        const onEvent = vi.fn();
        const stop = subscribeToCalls({ echo, userId: 'u-1', onEvent, onReconnect: vi.fn() });

        stop();

        expect(channel.stopListening).toHaveBeenCalledWith('.call.updated', onEvent);
        expect(echo.connector.pusher.connection.unbind).toHaveBeenCalled();
        expect(echo.leave).not.toHaveBeenCalled();
    });

    it('tolerates an Echo without a connector', () => {
        const channel = { listen: vi.fn() };

        expect(() => subscribeToCalls({ echo: { private: () => channel }, userId: 'u', onEvent: vi.fn(), onReconnect: vi.fn() })()).not.toThrow();
    });
});
