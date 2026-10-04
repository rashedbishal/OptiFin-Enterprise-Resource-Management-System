// WhatsApp inbound calling — Web Push Service Worker. A plain static file
// (not a Vite entry): it must be served from the site root with no build-time
// transform, since its scope is "every path at or below where it's served
// from" and nothing here needs bundling. Registered by
// resources/js/softphone/push.js.
//
// The payload shape matches NotificationChannels\WebPush\WebPushMessage::
// toArray() exactly (App\Notifications\IncomingWhatsAppCallNotification is
// the only sender today) — title/body/icon/badge/image/dir/lang/tag/
// renotify/requireInteraction/vibrate/actions/data, any of which may be
// absent (toArray() filters out unset fields).
self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        // A malformed payload still shows a generic notification rather than
        // silently doing nothing — the OS already woke this worker for it.
    }

    const title = payload.title || 'Incoming WhatsApp call';

    event.waitUntil(
        self.registration.showNotification(title, {
            body: payload.body,
            icon: payload.icon,
            badge: payload.badge,
            image: payload.image,
            dir: payload.dir,
            lang: payload.lang,
            tag: payload.tag,
            renotify: payload.renotify,
            requireInteraction: payload.requireInteraction,
            vibrate: payload.vibrate,
            actions: payload.actions,
            data: payload.data,
        }),
    );
});

// Focuses an already-open tab on the right page rather than always opening a
// new one — standard Push-notification click pattern.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data && event.notification.data.url;

    if (!url) {
        return;
    }

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const target = new URL(url, self.location.origin).href;

            for (const client of windows) {
                if (client.url === target && 'focus' in client) {
                    return client.focus();
                }
            }

            const existing = windows.find((client) => 'focus' in client);

            if (existing) {
                await existing.focus();

                return existing.navigate ? existing.navigate(target) : undefined;
            }

            return self.clients.openWindow(target);
        })(),
    );
});
