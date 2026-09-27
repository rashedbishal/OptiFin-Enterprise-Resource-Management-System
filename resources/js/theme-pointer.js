/**
 * Pointer light + tilt for the Titanium and Spatial theme styles.
 *
 * Writes the pointer position onto whichever themed surface is under a
 * mouse pointer, as CSS custom properties the theme stylesheets read:
 * - --ptr-x / --ptr-y (px, relative to the surface): the specular glint
 *   (Titanium) and the fill/rim spotlight (Spatial).
 * - --tilt-x / --tilt-y (deg): the 3D tilt of Spatial's cards. Only for
 *   small cards — a whole table tilting reads as the page wobbling.
 *
 * In every other theme this does nothing, and without it the stylesheets
 * fall back to a neutral resting position, so it is purely decorative.
 * Touch and pen are ignored; a press or drag resets the tilt so nothing is
 * dragged at an angle.
 */
export const POINTER_THEMES = ['titanium', 'spatial'];
export const TILT_THEMES = ['spatial'];

export const SURFACE_SELECTOR = [
    '.card-surface',
    '[data-flux-card]',
    '.kanban-card',
    '[data-flux-button]',
    '[data-flux-main] a.border',
].join(', ');

/** Cards only — a Flux button rendered as a link is also an `a.border`. */
export const TILT_SELECTOR = '.kanban-card, [data-flux-main] a.border:not([data-flux-button])';

/** Largest side (px) a surface may have and still tilt. */
export const TILT_MAX_SIZE = 520;

/** Tilt at the very edge of a card, in degrees. */
export const MAX_TILT_DEG = 7;

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

/**
 * Pointer position within a rect, plus the tilt that presses the card away
 * from the viewer where the pointer is (top → rotateX+, right → rotateY+).
 */
export function pointerState(rect, clientX, clientY, maxTilt = MAX_TILT_DEG) {
    const x = clientX - rect.left;
    const y = clientY - rect.top;
    const nx = rect.width > 0 ? clamp((x / rect.width) * 2 - 1, -1, 1) : 0;
    const ny = rect.height > 0 ? clamp((y / rect.height) * 2 - 1, -1, 1) : 0;

    return {
        x,
        y,
        tiltX: -ny * maxTilt,
        tiltY: nx * maxTilt,
    };
}

const round = (value) => Math.round(value * 100) / 100;

/**
 * @param {{ root?: HTMLElement, reducedMotion?: () => boolean }} [options]
 */
export function createPointerTracker({ root = document.documentElement, reducedMotion = () => false } = {}) {
    let current = null;

    const clear = (element) => {
        element?.style.removeProperty('--ptr-x');
        element?.style.removeProperty('--ptr-y');
        element?.style.removeProperty('--tilt-x');
        element?.style.removeProperty('--tilt-y');
    };

    const resetTilt = () => {
        current?.style.removeProperty('--tilt-x');
        current?.style.removeProperty('--tilt-y');
    };

    const reset = () => {
        clear(current);
        current = null;
    };

    const move = (event) => {
        const theme = root.dataset.theme;

        if (! POINTER_THEMES.includes(theme) || event.pointerType !== 'mouse') {
            reset();

            return;
        }

        const target = event.target instanceof Element ? event.target.closest(SURFACE_SELECTOR) : null;

        if (target !== current) {
            clear(current);
            current = target;
        }

        if (! target) {
            return;
        }

        const rect = target.getBoundingClientRect();
        const state = pointerState(rect, event.clientX, event.clientY);

        target.style.setProperty('--ptr-x', `${round(state.x)}px`);
        target.style.setProperty('--ptr-y', `${round(state.y)}px`);

        const canTilt = TILT_THEMES.includes(theme)
            && ! reducedMotion()
            && event.buttons === 0
            && Math.max(rect.width, rect.height) <= TILT_MAX_SIZE
            && target.matches(TILT_SELECTOR);

        if (canTilt) {
            target.style.setProperty('--tilt-x', `${round(state.tiltX)}deg`);
            target.style.setProperty('--tilt-y', `${round(state.tiltY)}deg`);
        } else {
            resetTilt();
        }
    };

    return { move, reset, resetTilt };
}

if (typeof document !== 'undefined') {
    const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    const tracker = createPointerTracker({ reducedMotion });
    const schedule = typeof window.requestAnimationFrame === 'function'
        ? window.requestAnimationFrame.bind(window)
        : (callback) => window.setTimeout(callback, 16);

    let pending = null;

    document.addEventListener(
        'pointermove',
        (event) => {
            if (pending === null) {
                schedule(() => {
                    const latest = pending;
                    pending = null;
                    tracker.move(latest);
                });
            }

            pending = event;
        },
        { passive: true },
    );

    document.addEventListener('pointerdown', () => tracker.resetTilt(), { capture: true, passive: true });
    document.addEventListener('dragstart', () => tracker.resetTilt(), { capture: true, passive: true });
    document.documentElement.addEventListener('pointerleave', () => tracker.reset());
    window.addEventListener('blur', () => tracker.reset());
    document.addEventListener('livewire:navigated', () => tracker.reset());
}
