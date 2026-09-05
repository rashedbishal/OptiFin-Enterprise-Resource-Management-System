<?php

namespace App\Listeners;

use App\Events\UserNotified;
use App\Models\TenantUser;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Turns every "database"-channel notification a TenantUser receives into a
 * real-time ping for the bell (App\Livewire\Tenant\NotificationBell),
 * without any of the three places that call ->notify() needing to know real
 * -time delivery exists at all. Listens to Laravel's own internal
 * NotificationSent event (fired after every channel send, for every
 * notification) rather than App\Events\UserNotified being dispatched
 * directly from SendNewLeadNotification/SendLeadAssignedNotification/
 * SendLeadAlertNotifications — so a future notification type sent via
 * ->notify() gets this for free.
 */
class BroadcastUserNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof TenantUser) {
            return;
        }

        event(new UserNotified($event->notifiable));
    }
}
