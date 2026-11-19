/**
 * localStorage key holding the marker this browser received when it paired
 * the phone (Settings → Calling app writes it — keep the two in sync).
 */
export const BROWSER_MARKER_STORAGE_KEY = 'optifin.callingApp.browserMarker';

/**
 * WhatsApp calling companion app — hands an outbound call to the native
 * OptiFin Calling app (earpiece/loudspeaker support a browser can't offer)
 * when, and only when, BOTH:
 *
 *   1. the server says this user has a paired phone (`config.paired`), and
 *   2. this browser is on that phone, known by EITHER
 *      a. the marker it saved when it paired the phone (Settings → Calling
 *         app) matching the paired device's `browserMarkerHash` — the
 *         primary signal, since Chrome's own detection proved unreliable
 *         on real phones; or
 *      b. Chrome reporting the app installed on this device
 *         (navigator.getInstalledRelatedApps(), backed by the web
 *         manifest's related_applications entry and the app's own
 *         asset_statements).
 *
 * On that same paired phone the browser softphone also stays out of the way
 * for INCOMING calls (index.js waits on `ready`): the app rings there instead.
 *
 * Anything else — a desktop browser, another browser, the app not installed,
 * not paired, the API missing or throwing, or detection simply not finished
 * yet when the agent clicks — leaves the existing browser softphone flow
 * completely untouched. Detection runs once, up front, so the Call click can
 * decide synchronously and the deep-link navigation still happens inside the
 * click's user gesture (Chrome blocks external-app launches without one).
 */
export function createCallingAppHandoff({
    config = {},
    navigatorImpl = typeof navigator === 'undefined' ? undefined : navigator,
    navigate = (url) => {
        window.location.href = url;
    },
    now = () => Date.now(),
    readMarker = () => {
        try {
            return window.localStorage.getItem(BROWSER_MARKER_STORAGE_KEY);
        } catch {
            return null;
        }
    },
    sha256Hex = async (text) => {
        const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));

        return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
    },
    detectTimeoutMs = 3000,
    setTimer = (fn, ms) => setTimeout(fn, ms),
} = {}) {
    const packageId = typeof config.packageId === 'string' ? config.packageId : null;
    const scheme = typeof config.scheme === 'string' ? config.scheme : null;
    const userId = typeof config.userId === 'string' ? config.userId : null;
    const markerHash = typeof config.browserMarkerHash === 'string' && config.browserMarkerHash !== '' ? config.browserMarkerHash : null;

    let installed = false;

    const markerMatches = async () => {
        if (!markerHash) {
            return false;
        }

        try {
            const marker = readMarker();

            return typeof marker === 'string' && marker !== '' && (await sha256Hex(marker)) === markerHash;
        } catch {
            return false;
        }
    };

    const chromeDetects = async () => {
        if (!packageId || typeof navigatorImpl?.getInstalledRelatedApps !== 'function') {
            return false;
        }

        try {
            const apps = await navigatorImpl.getInstalledRelatedApps();

            return Array.isArray(apps) && apps.some((app) => app?.id === packageId);
        } catch {
            return false;
        }
    };

    const detect = (async () => {
        if (config.paired !== true || !scheme || !userId) {
            return false;
        }

        return (await markerMatches()) || (await chromeDetects());
    })();

    // Capped: the softphone waits on this before starting (see index.js), so
    // a detection that never settles must fall back to "not the paired
    // phone" — the browser softphone, exactly as before — not hang it.
    const ready = Promise.race([
        detect,
        new Promise((resolve) => setTimer(() => resolve(false), detectTimeoutMs)),
    ]).then((result) => {
        installed = result === true;

        return installed;
    });

    return {
        ready,

        /** Whether a Call click right now would go to the app. */
        isActive: () => installed,

        /**
         * Opens the app for this lead and returns true, or returns false (and
         * does nothing) so the caller runs the normal browser call.
         */
        tryCall(leadId, leadName = null) {
            if (!installed || typeof leadId !== 'string' || leadId === '') {
                return false;
            }

            const params = new URLSearchParams({ lead: leadId, user: userId, t: String(now()) });

            if (typeof leadName === 'string' && leadName !== '') {
                params.set('name', leadName);
            }

            navigate(`${scheme}://call?${params.toString()}`);

            return true;
        },
    };
}
