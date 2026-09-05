<?php

namespace App\Events;

use App\Models\TenantUser;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires whenever a "database"-channel notification lands for a TenantUser
 * — see App\Listeners\BroadcastUserNotification, which listens to Laravel's
 * own internal NotificationSent event rather than being dispatched from
 * each of the three places that call ->notify() (SendNewLeadNotification,
 * SendLeadAssignedNotification, SendLeadAlertNotifications), so a future
 * notification type gets real-time delivery for free the moment it's sent
 * via that channel, with nothing to remember at the call site.
 *
 * Carries no notification content itself: App\Livewire\Tenant\NotificationBell
 * just re-queries its own unread count/list on receipt, the same as a
 * manual refresh would — nothing here to drift out of sync with how that
 * component actually presents a notification.
 */
class UserNotified implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TenantUser $tenantUser) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant-user.'.$this->tenantUser->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
