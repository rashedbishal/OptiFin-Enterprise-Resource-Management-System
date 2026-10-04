<?php

namespace App\Listeners;

use NotificationChannels\WebPush\Events\NotificationFailed;

/**
 * Self-healing: a push service (Chrome's FCM, Apple's APNs, ...) reports a
 * subscription as gone once the user has uninstalled the PWA, revoked
 * notification permission, or the subscription has simply expired — there is
 * no way to detect that ahead of a send attempt. Auto-discovered the same
 * way App\Listeners\BroadcastUserNotification is (a handle() method
 * type-hinted with the event, no explicit registration needed).
 */
class DeleteExpiredPushSubscription
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->report->isSubscriptionExpired()) {
            $event->subscription->delete();
        }
    }
}
