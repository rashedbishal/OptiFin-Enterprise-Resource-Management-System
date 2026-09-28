import { describe, expect, it, vi } from 'vitest';
import { DragGesture } from './drag.js';

function gesture(overrides = {}) {
    const onTrigger = vi.fn();
    const onCancel = vi.fn();
    const onMove = vi.fn();
    const g = new DragGesture({ tapThreshold: 8, dragThreshold: 64, onTrigger, onCancel, onMove, ...overrides });

    return { g, onTrigger, onCancel, onMove };
}

describe('a quick tap (movement never exceeds tapThreshold)', () => {
    it('triggers on release even though it never reached dragThreshold', () => {
        const { g, onTrigger, onCancel } = gesture();

        g.start(100, 100);
        g.end(103, 101); // 3.16px — well under tapThreshold

        expect(onTrigger).toHaveBeenCalledTimes(1);
        expect(onCancel).not.toHaveBeenCalled();
    });

    it('triggers even with zero movement at all', () => {
        const { g, onTrigger } = gesture();

        g.start(50, 50);
        g.end(50, 50);

        expect(onTrigger).toHaveBeenCalledTimes(1);
    });
});

describe('a completed drag (released at or past dragThreshold)', () => {
    it('triggers when released exactly at the threshold', () => {
        const { g, onTrigger } = gesture();

        g.start(0, 0);
        g.move(40, 0);
        g.end(64, 0);

        expect(onTrigger).toHaveBeenCalledTimes(1);
    });

    it('triggers when released well past the threshold', () => {
        const { g, onTrigger } = gesture();

        g.start(0, 0);
        g.move(50, 0);
        g.end(120, 0);

        expect(onTrigger).toHaveBeenCalledTimes(1);
    });

    it('triggers regardless of drag direction', () => {
        const { g, onTrigger } = gesture();

        g.start(0, 0);
        g.end(-30, -60); // hypot ≈ 67.08

        expect(onTrigger).toHaveBeenCalledTimes(1);
    });
});

describe('a partial drag (moved past tapThreshold but released short of dragThreshold)', () => {
    it('cancels rather than triggering', () => {
        const { g, onTrigger, onCancel } = gesture();

        g.start(0, 0);
        g.move(40, 0);
        g.end(20, 0); // pulled back to 20px at release, but it was a real drag (moved 40px at peak)

        expect(onCancel).toHaveBeenCalledTimes(1);
        expect(onTrigger).not.toHaveBeenCalled();
    });

    it('cancels when released just short of the threshold', () => {
        const { g, onCancel, onTrigger } = gesture();

        g.start(0, 0);
        g.move(63.9, 0); // a real drag was attempted, past tapThreshold
        g.end(63.9, 0); // but released just short of dragThreshold (64)

        expect(onCancel).toHaveBeenCalledTimes(1);
        expect(onTrigger).not.toHaveBeenCalled();
    });
});

describe('reported movement (onMove)', () => {
    it('reports live offsets while dragging and resets to zero on start and on release', () => {
        const { g, onMove } = gesture();

        g.start(10, 10);
        expect(onMove).toHaveBeenLastCalledWith(0, 0, 0);

        g.move(30, 10); // dx=20, dy=0
        expect(onMove).toHaveBeenLastCalledWith(20, 0, 20);

        g.end(30, 10);
        expect(onMove).toHaveBeenLastCalledWith(0, 0, 0);
    });

    it('a move before start() does nothing', () => {
        const { g, onMove } = gesture();

        g.move(999, 999);

        expect(onMove).not.toHaveBeenCalled();
    });
});

describe('cancel()', () => {
    it('snaps back and never triggers, even after a drag past the threshold', () => {
        const { g, onTrigger, onCancel, onMove } = gesture();

        g.start(0, 0);
        g.move(100, 0);
        g.cancel();

        expect(onCancel).toHaveBeenCalledTimes(1);
        expect(onTrigger).not.toHaveBeenCalled();
        expect(onMove).toHaveBeenLastCalledWith(0, 0, 0);
    });

    it('does nothing when the gesture is not active', () => {
        const { g, onCancel } = gesture();

        g.cancel();

        expect(onCancel).not.toHaveBeenCalled();
    });
});

describe('a second start() after end() begins a fresh gesture', () => {
    it('does not carry over the previous gesture\'s max distance', () => {
        const { g, onTrigger, onCancel } = gesture();

        g.start(0, 0);
        g.move(100, 0);
        g.end(10, 0); // partial drag, cancels
        expect(onCancel).toHaveBeenCalledTimes(1);

        g.start(0, 0);
        g.end(3, 0); // fresh tap, should trigger — not tainted by the earlier drag

        expect(onTrigger).toHaveBeenCalledTimes(1);
    });
});
