import { defaultCsrfHeaders } from './api.js';

/**
 * WhatsApp inbound calling — registers this browser for Web Push (public/sw.js)
 * so an incoming call can wake the OS even with every tab closed. Entirely
 * separate from SoftphoneController/ringtone.js: this only ever runs once,
 * on an explicit "Enable notifications" click (browsers require a real user
 * gesture for Notification.requestPermission()), never automatically.
 *
 * Reuses api.js's defaultCsrfHeaders() rather than createApi() itself — the
 * subscribe/unsubscribe requests don't need createApi()'s call-id templating
 * or its ApiError taxonomy, and keeping this fully separate means api.js and
 * its existing tests stay untouched.
 */

/**
 * The standard VAPID-key conversion every Push API integration needs: the
 * server hands over a URL-safe base64 string, but PushManager.subscribe()'s
 * applicationServerKey wants raw bytes.
 */
export function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);

    return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

export function createPushSubscriber({
    nav = typeof navigator === 'undefined' ? undefined : navigator,
    notificationApi = typeof Notification === 'undefined' ? undefined : Notification,
    vapidPublicKey,
    urls,
    fetchImpl = (...args) => fetch(...args),
    csrfHeaders = defaultCsrfHeaders,
    swUrl = '/sw.js',
} = {}) {
    const supported = Boolean(nav?.serviceWorker && typeof window !== 'undefined' && window.PushManager && notificationApi && vapidPublicKey);

    async function send(method, endpoint, body) {
        try {
            await fetchImpl(endpoint, {
                method,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                body: JSON.stringify(body),
            });
        } catch {
            // Best-effort, same principle as ringback/ringtone: a failed
            // subscribe/unsubscribe round-trip never breaks the caller.
        }
    }

    async function register() {
        if (!supported) {
            return null;
        }

        try {
            return await nav.serviceWorker.register(swUrl);
        } catch {
            return null;
        }
    }

    function permission() {
        return supported ? notificationApi.permission : 'unsupported';
    }

    /**
     * Goes through register() (idempotent — the browser just hands back the
     * existing registration if one's already there) rather than
     * navigator.serviceWorker.ready. `ready` only resolves once a worker has
     * installed AND activated for this origin; on a first-ever visit nothing
     * has registered yet, so awaiting it before anything has called
     * register() deadlocks forever — the exact "stuck on Enabling…" bug this
     * fixes. A registration's pushManager is usable immediately, with no
     * need to wait for activation.
     */
    async function currentSubscription() {
        const registration = await register();

        if (!registration) {
            return null;
        }

        try {
            return await registration.pushManager.getSubscription();
        } catch {
            return null;
        }
    }

    async function isSubscribed() {
        return (await currentSubscription()) !== null;
    }

    /**
     * Must be called directly from a user gesture (a button click) — both
     * Notification.requestPermission() and, on some browsers,
     * pushManager.subscribe() require one.
     *
     * Resolves { ok, reason, error } rather than a bare boolean so the UI can
     * show *why* it failed (the user-facing complaint this was added for:
     * silent failure looks identical to "nothing happened" from a click).
     * reason is one of: null (success), 'unsupported', 'permission-denied',
     * 'registration-failed', 'subscribe-failed'. `error`, when present, is
     * the raw Error for console logging only — never shown to the user.
     */
    async function enable() {
        if (!supported) {
            return { ok: false, reason: 'unsupported' };
        }

        const registration = await register();

        if (!registration) {
            return { ok: false, reason: 'registration-failed' };
        }

        const existing = await registration.pushManager.getSubscription().catch(() => null);

        if (existing) {
            await send('POST', urls.subscribe, existing.toJSON());

            return { ok: true, reason: null };
        }

        const permissionResult = await notificationApi.requestPermission();

        if (permissionResult !== 'granted') {
            return { ok: false, reason: 'permission-denied' };
        }

        let subscription;

        try {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });
        } catch (error) {
            return { ok: false, reason: 'subscribe-failed', error };
        }

        await send('POST', urls.subscribe, subscription.toJSON());

        return { ok: true, reason: null };
    }

    async function disable() {
        const subscription = await currentSubscription();

        if (!subscription) {
            return true;
        }

        const endpoint = subscription.endpoint;

        try {
            await subscription.unsubscribe();
        } catch {
            // Still tell the server to forget it — a stale server-side
            // subscription just means one harmless failed push later,
            // self-healed by DeleteExpiredPushSubscription.
        }

        await send('DELETE', urls.unsubscribe, { endpoint });

        return true;
    }

    return {
        supported,
        register,
        permission,
        isSubscribed,
        enable,
        disable,
    };
}
