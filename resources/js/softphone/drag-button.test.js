import { beforeEach, describe, expect, it, vi } from 'vitest';
import { registerCallDragButton } from './drag-button.js';

/**
 * The Alpine adapter over DragGesture. drag.test.js already proves the
 * gesture logic itself; this only proves the wiring — pointer capture is
 * requested, events are forwarded to the gesture with clientX/clientY, and a
 * completed gesture dispatches `call-drag-trigger` (not a direct callback —
 * see drag-button.js for why) — the exact class of gap that shipped without a
 * forward once already (index.js/hangup).
 */

function component(config) {
    let factory;
    registerCallDragButton({ data: (name, f) => { factory = f; } });
    const el = { setPointerCapture: vi.fn() };
    const c = factory(config);
    c.$el = el;
    c.$dispatch = vi.fn();
    c.init();

    return { c, el };
}

describe('the Alpine adapter over DragGesture', () => {
    it('exposes start, move, end, cancel and dragStyle', () => {
        const { c } = component();

        expect(typeof c.start).toBe('function');
        expect(typeof c.move).toBe('function');
        expect(typeof c.end).toBe('function');
        expect(typeof c.cancel).toBe('function');
        expect(c.dragStyle).toBe('');
    });

    it('requests pointer capture on start, using the pointer event\'s own id', () => {
        const { c, el } = component();

        c.start({ pointerId: 7, clientX: 10, clientY: 10 });

        expect(el.setPointerCapture).toHaveBeenCalledWith(7);
    });

    it('reports live offsets through dragStyle while dragging, and resets after release', () => {
        const { c } = component();

        c.start({ pointerId: 1, clientX: 100, clientY: 100 });
        c.move({ clientX: 130, clientY: 100 });

        expect(c.dragging).toBe(true);
        expect(c.dragStyle).toBe('transform: translate(30px, 0px)');

        c.end({ clientX: 130, clientY: 100 });

        expect(c.dragging).toBe(false);
        expect(c.dragStyle).toBe('');
    });

    it('dispatches call-drag-trigger on a tap', () => {
        const { c } = component();

        c.start({ pointerId: 1, clientX: 50, clientY: 50 });
        c.end({ clientX: 51, clientY: 50 });

        expect(c.$dispatch).toHaveBeenCalledWith('call-drag-trigger');
    });

    it('dispatches call-drag-trigger on a completed drag', () => {
        const { c } = component();

        c.start({ pointerId: 1, clientX: 0, clientY: 0 });
        c.move({ clientX: 40, clientY: 0 });
        c.end({ clientX: 70, clientY: 0 });

        expect(c.$dispatch).toHaveBeenCalledWith('call-drag-trigger');
    });

    it('does not dispatch on a partial drag, and cancel() never dispatches', () => {
        const { c } = component();

        c.start({ pointerId: 1, clientX: 0, clientY: 0 });
        c.move({ clientX: 40, clientY: 0 });
        c.end({ clientX: 20, clientY: 0 });
        expect(c.$dispatch).not.toHaveBeenCalled();

        c.start({ pointerId: 2, clientX: 0, clientY: 0 });
        c.move({ clientX: 100, clientY: 0 });
        c.cancel();
        expect(c.$dispatch).not.toHaveBeenCalled();
    });

    it('clamps the visual offset to maxOffset, even for a very large drag, while preserving direction', () => {
        const { c } = component();

        c.start({ pointerId: 1, clientX: 0, clientY: 0 });
        c.move({ clientX: 300, clientY: 0 }); // far beyond the default maxOffset of 96

        // Clamped to the default maxOffset (96), same direction as the drag.
        expect(c.dragStyle).toBe('transform: translate(96px, 0px)');
    });

    it('clamps a diagonal drag to maxOffset while keeping its direction proportional', () => {
        const { c } = component();

        c.start({ pointerId: 1, clientX: 0, clientY: 0 });
        c.move({ clientX: 300, clientY: 400 }); // distance 500, direction 3:4

        const match = c.dragStyle.match(/translate\(([-\d.]+)px, ([-\d.]+)px\)/);
        const [dx, dy] = [Number(match[1]), Number(match[2])];

        expect(Math.hypot(dx, dy)).toBeCloseTo(96, 5);
        expect(dx / dy).toBeCloseTo(300 / 400, 5); // same direction, just shortened
    });

    it('a large drag still triggers on release (the trigger decision is never clamped)', () => {
        const { c } = component();

        c.start({ pointerId: 1, clientX: 0, clientY: 0 });
        c.move({ clientX: 300, clientY: 0 });
        c.end({ clientX: 300, clientY: 0 });

        expect(c.$dispatch).toHaveBeenCalledWith('call-drag-trigger');
    });

    it('a custom maxOffset clamps accordingly', () => {
        const { c } = component({ maxOffset: 40 });

        c.start({ pointerId: 1, clientX: 0, clientY: 0 });
        c.move({ clientX: 200, clientY: 0 });

        expect(c.dragStyle).toBe('transform: translate(40px, 0px)');
    });

    it('respects a custom threshold config', () => {
        const { c } = component({ tapThreshold: 2, dragThreshold: 10 });

        c.start({ pointerId: 1, clientX: 0, clientY: 0 });
        c.move({ clientX: 5, clientY: 0 }); // past the custom tapThreshold of 2
        c.end({ clientX: 5, clientY: 0 }); // short of the custom dragThreshold of 10

        expect(c.$dispatch).not.toHaveBeenCalled();
    });
});
