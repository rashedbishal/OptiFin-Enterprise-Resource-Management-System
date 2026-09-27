/**
 * Theme style switcher — Classic / Neumorphic / Glass / Titanium / Spatial.
 *
 * Orthogonal to Flux's light/dark appearance (the `.dark` class on <html>):
 * the style lives in `data-theme` on <html>, so every style has both a light
 * and a dark variant, and the two controls never fight over the same
 * attribute. Styles themselves live in resources/css/themes/*.css.
 *
 * Three moments need the attribute set:
 * 1. First paint — an inline script in partials/head.blade.php sets it before
 *    any CSS applies (no flash of the classic theme).
 * 2. wire:navigate — Livewire copies the new page's <html> attributes over the
 *    current ones and REMOVES any the server HTML doesn't carry, which strips
 *    data-theme on every navigation. `onSwap` runs synchronously right after
 *    that swap, before the browser paints, so re-applying there is flash-free.
 * 3. A pick in the switcher (the Alpine store below), or a pick in another tab
 *    (the `storage` event).
 */
export const STORAGE_KEY = 'optifin.theme-style';
export const THEME_STYLES = ['classic', 'neo', 'glass', 'titanium', 'spatial'];
export const DEFAULT_THEME_STYLE = 'classic';

/** Time the circular reveal runs; keep in sync with `theme-style-reveal` in app.css. */
const REVEAL_MS = 900;

export function normalizeThemeStyle(style) {
    return THEME_STYLES.includes(style) ? style : DEFAULT_THEME_STYLE;
}

export function readThemeStyle(storage = window.localStorage) {
    try {
        return normalizeThemeStyle(storage.getItem(STORAGE_KEY));
    } catch {
        return DEFAULT_THEME_STYLE;
    }
}

export function applyThemeStyle(style, root = document.documentElement) {
    root.dataset.theme = normalizeThemeStyle(style);
}

/**
 * Persist + apply a style. When the browser supports View Transitions (and the
 * user hasn't asked for reduced motion), the new theme is revealed as a circle
 * growing out of the point that was last clicked — the switch itself.
 */
export function setThemeStyle(style, { storage = window.localStorage, root = document.documentElement, animate = true } = {}) {
    const next = normalizeThemeStyle(style);

    try {
        storage.setItem(STORAGE_KEY, next);
    } catch {
        // Private mode / blocked storage: still switch for this page view.
    }

    if (root.dataset.theme === next) {
        return;
    }

    const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    if (! animate || reduceMotion || typeof document.startViewTransition !== 'function') {
        applyThemeStyle(next, root);

        return;
    }

    root.classList.add('theme-style-transition');

    const transition = document.startViewTransition(() => applyThemeStyle(next, root));
    const cleanup = () => root.classList.remove('theme-style-transition');

    transition.finished.then(cleanup, cleanup);
    // Belt and braces: never leave the class behind if `finished` never settles.
    window.setTimeout(cleanup, REVEAL_MS + 400);
}

if (typeof document !== 'undefined') {
    applyThemeStyle(readThemeStyle());

    // Remember where the pointer last went down, so the reveal starts there.
    document.addEventListener(
        'pointerdown',
        (event) => {
            document.documentElement.style.setProperty('--theme-reveal-x', `${event.clientX}px`);
            document.documentElement.style.setProperty('--theme-reveal-y', `${event.clientY}px`);
        },
        { capture: true, passive: true },
    );

    document.addEventListener('livewire:navigating', (event) => {
        event.detail?.onSwap?.(() => applyThemeStyle(readThemeStyle()));
    });

    document.addEventListener('livewire:navigated', () => applyThemeStyle(readThemeStyle()));

    const registerStore = (Alpine) => {
        if (Alpine.store('themeStyle')) {
            return;
        }

        Alpine.store('themeStyle', {
            active: readThemeStyle(),

            /** Getter/setter pair so `x-model="$store.themeStyle.current"` just works. */
            get current() {
                return this.active;
            },

            set current(style) {
                this.active = normalizeThemeStyle(style);
                setThemeStyle(this.active);
            },
        });

        window.addEventListener('storage', (event) => {
            if (event.key !== STORAGE_KEY) {
                return;
            }

            const style = normalizeThemeStyle(event.newValue);
            Alpine.store('themeStyle').active = style;
            applyThemeStyle(style);
        });
    };

    // The Alpine instance is provided by Livewire; register before or after it starts.
    document.addEventListener('alpine:init', () => registerStore(window.Alpine));

    if (window.Alpine?.store) {
        registerStore(window.Alpine);
    }
}
