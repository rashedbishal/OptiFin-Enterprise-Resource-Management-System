import { beforeEach, describe, expect, it } from 'vitest';
import { MAX_TILT_DEG, createPointerTracker, pointerState } from './theme-pointer.js';

const mockRect = (element, rect) => {
    element.getBoundingClientRect = () => ({ left: 0, top: 0, ...rect });
};

const pointer = (target, overrides = {}) => ({
    target,
    clientX: 150,
    clientY: 0,
    buttons: 0,
    pointerType: 'mouse',
    ...overrides,
});

describe('theme pointer', () => {
    let root;
    let card;
    let child;
    let tracker;

    beforeEach(() => {
        root = document.createElement('html');
        document.body.innerHTML = '<div class="kanban-card"><span>Lead</span></div>';
        card = document.body.querySelector('.kanban-card');
        child = card.querySelector('span');
        mockRect(card, { width: 300, height: 200 });
        tracker = createPointerTracker({ root });
    });

    it('tilts away from the viewer where the pointer is', () => {
        const rect = { left: 0, top: 0, width: 200, height: 100 };

        expect(pointerState(rect, 100, 0)).toMatchObject({ x: 100, y: 0, tiltX: MAX_TILT_DEG, tiltY: 0 });
        expect(pointerState(rect, 200, 50)).toMatchObject({ tiltX: -0, tiltY: MAX_TILT_DEG });
        expect(pointerState(rect, 900, 900)).toMatchObject({ tiltX: -MAX_TILT_DEG, tiltY: MAX_TILT_DEG });
    });

    it('does nothing outside the pointer-lit theme styles', () => {
        root.dataset.theme = 'glass';
        tracker.move(pointer(child));

        expect(card.style.getPropertyValue('--ptr-x')).toBe('');
    });

    it('lights and tilts the surface under the pointer in Spatial', () => {
        root.dataset.theme = 'spatial';
        tracker.move(pointer(child));

        expect(card.style.getPropertyValue('--ptr-x')).toBe('150px');
        expect(card.style.getPropertyValue('--tilt-x')).toBe(`${MAX_TILT_DEG}deg`);
    });

    it('lights but never tilts in Titanium, or when a surface is too large to tilt', () => {
        root.dataset.theme = 'titanium';
        tracker.move(pointer(child));

        expect(card.style.getPropertyValue('--ptr-y')).toBe('0px');
        expect(card.style.getPropertyValue('--tilt-x')).toBe('');

        root.dataset.theme = 'spatial';
        mockRect(card, { width: 1200, height: 200 });
        tracker.move(pointer(child));

        expect(card.style.getPropertyValue('--tilt-x')).toBe('');
    });

    it('never tilts a button that happens to be rendered as a link', () => {
        document.body.innerHTML = '<main data-flux-main><a class="border" data-flux-button>Import</a></main>';
        const link = document.body.querySelector('a');
        mockRect(link, { width: 120, height: 32 });
        root.dataset.theme = 'spatial';
        tracker.move(pointer(link));

        expect(link.style.getPropertyValue('--ptr-x')).toBe('150px');
        expect(link.style.getPropertyValue('--tilt-x')).toBe('');
    });

    it('ignores touch, and resets the tilt on press and when leaving the surface', () => {
        root.dataset.theme = 'spatial';
        tracker.move(pointer(child, { pointerType: 'touch' }));
        expect(card.style.getPropertyValue('--ptr-x')).toBe('');

        tracker.move(pointer(child));
        tracker.resetTilt();
        expect(card.style.getPropertyValue('--tilt-x')).toBe('');
        expect(card.style.getPropertyValue('--ptr-x')).toBe('150px');

        tracker.move(pointer(document.body));
        expect(card.style.getPropertyValue('--ptr-x')).toBe('');
    });
});

