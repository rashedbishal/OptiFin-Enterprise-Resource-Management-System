/**
 * WhatsApp calling companion app — hands an outbound call to the native
 * OptiFin Calling app (earpiece/loudspeaker support a browser can't offer)
 * when, and only when, BOTH:
 *
 *   1. the server says this user has a paired phone (`config.paired`), and
 *   2. Chrome reports the app is installed on THIS device
 *      (navigator.getInstalledRelatedApps(), Android Chrome/Edge only —
 *      backed by the site's /.well-known/assetlinks.json and the web
 *      manifest's related_applications entry).
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
} = {}) {
    const packageId = typeof config.packageId === 'string' ? config.packageId : null;
    const scheme = typeof config.scheme === 'string' ? config.scheme : null;
    const userId = typeof config.userId === 'string' ? config.userId : null;

    let installed = false;

    const ready = (async () => {
        if (config.paired !== true || !packageId || !scheme || !userId || typeof navigatorImpl?.getInstalledRelatedApps !== 'function') {
            return false;
        }

        try {
            const apps = await navigatorImpl.getInstalledRelatedApps();

            installed = Array.isArray(apps) && apps.some((app) => app?.id === packageId);
        } catch {
            installed = false;
        }

        return installed;
    })();

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
