import { describe, expect, it, vi } from 'vitest';
import { createOwnership } from './ownership.js';

/** A minimal in-memory BroadcastChannel bus shared by several "tabs". */
function bus() {
    const members = new Set();

    return (name) => {
        const channel = {
            name,
            onmessage: null,
            postMessage: (data) => members.forEach((other) => other !== channel && other.onmessage?.({ data })),
            close: () => members.delete(channel),
        };
        members.add(channel);

        return channel;
    };
}

const fixedTimers = () => ({ setInterval: vi.fn(() => 1), clearInterval: vi.fn() });

describe('softphone ownership', () => {
    it('lets another tab see which call this tab owns, and forget it on release', () => {
        const factory = bus();
        const a = createOwnership({ channelFactory: factory, tabId: 'a', timers: fixedTimers() });
        const b = createOwnership({ channelFactory: factory, tabId: 'b', timers: fixedTimers() });
        const changes = vi.fn();
        b.onChange(changes);

        a.claim('call-1');

        expect([...b.otherOwnedCallIds()]).toEqual(['call-1']);
        expect(changes).toHaveBeenCalledTimes(1);

        a.release();

        expect(b.otherOwnedCallIds().size).toBe(0);
        expect(changes).toHaveBeenCalledTimes(2);
    });

    it('never reports its own claim as another tab\'s', () => {
        const factory = bus();
        const a = createOwnership({ channelFactory: factory, tabId: 'a', timers: fixedTimers() });

        a.claim('call-1');

        expect(a.otherOwnedCallIds().size).toBe(0);
    });

    it('tells a tab that opens later, through the hello handshake', () => {
        const factory = bus();
        const a = createOwnership({ channelFactory: factory, tabId: 'a', timers: fixedTimers() });
        a.claim('call-1');

        const late = createOwnership({ channelFactory: factory, tabId: 'late', timers: fixedTimers() });

        expect([...late.otherOwnedCallIds()]).toEqual(['call-1']);
    });

    it('forgets an owner that stopped announcing (a closed or crashed tab)', () => {
        let clock = 0;
        const factory = bus();
        const a = createOwnership({ channelFactory: factory, tabId: 'a', timers: fixedTimers() });
        const b = createOwnership({ channelFactory: factory, tabId: 'b', now: () => clock, ttlMs: 15000, timers: fixedTimers() });

        a.claim('call-1');
        expect(b.otherOwnedCallIds().size).toBe(1);

        clock = 16000;

        expect(b.otherOwnedCallIds().size).toBe(0);
    });

    it('re-announces on a heartbeat while it owns a call and stops when released', () => {
        const timers = fixedTimers();
        const a = createOwnership({ channelFactory: bus(), tabId: 'a', timers });

        a.claim('call-1');
        expect(timers.setInterval).toHaveBeenCalledTimes(1);

        a.release();
        expect(timers.clearInterval).toHaveBeenCalledTimes(1);
    });

    it('degrades safely when BroadcastChannel is unavailable', () => {
        const ownership = createOwnership({ channelFactory: () => { throw new Error('unsupported'); }, tabId: 'a', timers: fixedTimers() });

        expect(ownership.available).toBe(false);
        expect(() => { ownership.claim('call-1'); ownership.release(); ownership.close(); }).not.toThrow();
        expect(ownership.otherOwnedCallIds().size).toBe(0);
    });

    it('carries only a call id and a tab id — never an SDP', () => {
        const posted = [];
        const factory = () => ({ postMessage: (message) => posted.push(message), close() {} });
        const a = createOwnership({ channelFactory: factory, tabId: 'a', timers: fixedTimers() });

        a.claim('call-1');
        a.release();

        expect(posted.every((message) => Object.keys(message).every((key) => ['t', 'tab', 'callId'].includes(key)))).toBe(true);
    });
});
