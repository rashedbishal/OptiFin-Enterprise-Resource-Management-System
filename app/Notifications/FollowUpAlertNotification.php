<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Notifications\Notification;

/**
 * The bell-notification counterpart to Lead::followUpAlertActive()'s Kanban
 * card shimmer — see MeetingAlertNotification for why this repeats daily
 * instead of firing once.
 */
class FollowUpAlertNotification extends Notification
{
    public function __construct(private readonly Lead $lead) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'name' => $this->lead->name,
            'company' => $this->lead->company,
            'next_follow_up_at' => $this->lead->next_follow_up_at?->toDateString(),
        ];
    }
}
