import { beforeEach, describe, expect, it, vi } from 'vitest';
import { STORAGE_KEY, applyThemeStyle, readThemeStyle, setThemeStyle } from './theme-style.js';

describe('theme style', () => {
    beforeEach(() => {
        window.localStorage.clear();
        delete document.documentElement.dataset.theme;
        document.documentElement.className = '';
    });

    it('falls back to classic for a missing, unknown or unreadable stored value', () => {
        expect(readThemeStyle()).toBe('classic');

        window.localStorage.setItem(STORAGE_KEY, 'retro');
        expect(readThemeStyle()).toBe('classic');

        const blocked = { getItem: () => { throw new Error('SecurityError'); } };
        expect(readThemeStyle(blocked)).toBe('classic');
    });

    it('persists the chosen style and applies it to <html>', () => {
        setThemeStyle('glass', { animate: false });

        expect(window.localStorage.getItem(STORAGE_KEY)).toBe('glass');
        expect(document.documentElement.dataset.theme).toBe('glass');
        expect(readThemeStyle()).toBe('glass');
    });

    it('never applies an unknown style', () => {
        applyThemeStyle('retro');

        expect(document.documentElement.dataset.theme).toBe('classic');
    });

    it('reveals the new style through a view transition when the browser supports one', async () => {
        let update;
        document.startViewTransition = vi.fn((callback) => {
            update = callback;

            return { finished: Promise.resolve() };
        });

        setThemeStyle('neo');

        expect(document.documentElement.classList.contains('theme-style-transition')).toBe(true);
        update();
        expect(document.documentElement.dataset.theme).toBe('neo');

        await Promise.resolve();
        expect(document.documentElement.classList.contains('theme-style-transition')).toBe(false);

        delete document.startViewTransition;
    });

    it('restores the style after wire:navigate strips it from <html>', () => {
        window.localStorage.setItem(STORAGE_KEY, 'neo');
        const swapCallbacks = [];

        document.dispatchEvent(new CustomEvent('livewire:navigating', {
            detail: { onSwap: (callback) => swapCallbacks.push(callback) },
        }));

        // Livewire copies the server's <html> attributes over, removing data-theme...
        delete document.documentElement.dataset.theme;
        // ...then runs onSwap callbacks before the browser paints.
        swapCallbacks.forEach((callback) => callback());

        expect(document.documentElement.dataset.theme).toBe('neo');
    });

    it('exposes a store whose current value drives the style (x-model binding)', () => {
        const stores = {};
        window.Alpine = { store: (name, value) => (value === undefined ? stores[name] : (stores[name] = value)) };

        document.dispatchEvent(new Event('alpine:init'));
        stores.themeStyle.current = 'glass';

        expect(stores.themeStyle.current).toBe('glass');
        expect(document.documentElement.dataset.theme).toBe('glass');
        expect(window.localStorage.getItem(STORAGE_KEY)).toBe('glass');

        delete window.Alpine;
    });
});
