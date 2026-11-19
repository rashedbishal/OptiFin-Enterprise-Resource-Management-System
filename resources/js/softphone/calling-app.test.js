import { describe, expect, it, vi } from 'vitest';
import { createCallingAppHandoff } from './calling-app.js';

/**
 * Calling companion app hand-off: a lead's Call button opens the paired
 * native app ONLY when the user has a paired phone AND Chrome reports the app
 * installed on this device — every other case must leave the browser call
 * untouched (tryCall() returns false and navigates nowhere).
 */

const pairedConfig = { paired: true, packageId: 'com.optifin.calling', scheme: 'optifin-calling', userId: 'user-1' };

function installedNavigator(apps = [{ platform: 'play', id: 'com.optifin.calling' }]) {
    return { getInstalledRelatedApps: vi.fn(async () => apps) };
}

describe('createCallingAppHandoff', () => {
    it('opens the app with the lead, the user and the click time when paired and installed', async () => {
        const navigate = vi.fn();
        const handoff = createCallingAppHandoff({ config: pairedConfig, navigatorImpl: installedNavigator(), navigate, now: () => 1700000000000 });

        await handoff.ready;

        expect(handoff.tryCall('lead-9', 'Ada Lovelace')).toBe(true);
        expect(navigate).toHaveBeenCalledWith('optifin-calling://call?lead=lead-9&user=user-1&t=1700000000000&name=Ada+Lovelace');
    });

    it('never even asks the browser when the user has no paired phone', async () => {
        const navigatorImpl = installedNavigator();
        const navigate = vi.fn();
        const handoff = createCallingAppHandoff({ config: { ...pairedConfig, paired: false }, navigatorImpl, navigate });

        expect(await handoff.ready).toBe(false);
        expect(navigatorImpl.getInstalledRelatedApps).not.toHaveBeenCalled();
        expect(handoff.tryCall('lead-9')).toBe(false);
        expect(navigate).not.toHaveBeenCalled();
    });

    it('falls back to the browser when the app is not installed on this device', async () => {
        const navigate = vi.fn();
        const handoff = createCallingAppHandoff({ config: pairedConfig, navigatorImpl: installedNavigator([{ platform: 'play', id: 'com.other.app' }]), navigate });

        await handoff.ready;

        expect(handoff.tryCall('lead-9')).toBe(false);
        expect(navigate).not.toHaveBeenCalled();
    });

    it('falls back to the browser when the detection API is missing (desktop, other browsers)', async () => {
        const handoff = createCallingAppHandoff({ config: pairedConfig, navigatorImpl: {}, navigate: vi.fn() });

        expect(await handoff.ready).toBe(false);
        expect(handoff.tryCall('lead-9')).toBe(false);
    });

    it('falls back to the browser when detection throws', async () => {
        const navigatorImpl = { getInstalledRelatedApps: vi.fn(async () => { throw new Error('not allowed'); }) };
        const handoff = createCallingAppHandoff({ config: pairedConfig, navigatorImpl, navigate: vi.fn() });

        expect(await handoff.ready).toBe(false);
        expect(handoff.tryCall('lead-9')).toBe(false);
    });

    it('falls back to the browser for a click that lands before detection has finished', () => {
        const navigatorImpl = { getInstalledRelatedApps: () => new Promise(() => {}) };
        const navigate = vi.fn();
        const handoff = createCallingAppHandoff({ config: pairedConfig, navigatorImpl, navigate });

        expect(handoff.tryCall('lead-9')).toBe(false);
        expect(navigate).not.toHaveBeenCalled();
    });
});
