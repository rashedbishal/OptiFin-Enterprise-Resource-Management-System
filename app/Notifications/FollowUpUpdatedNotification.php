<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Notifications\Notification;

/**
 * Sent to every other active Client Admin (see App\Services\LeadAdminNotifier)
 * when a lead's next follow-up date is set for the first time or changed to
 * a different date — mirrors MeetingUpdatedNotification for
 * next_follow_up_at instead of meeting_date. Clearing the date entirely is
 * a cancellation, handled by FollowUpCancelledNotification instead.
 */
class FollowUpUpdatedNotification extends Notification
{
    public function __construct(
        private readonly Lead $lead,
        private readonly TenantUser $changedBy,
        private readonly bool $rescheduled,
    ) {}

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
            'changed_by' => $this->changedBy->name,
            'rescheduled' => $this->rescheduled,
            'next_follow_up_at' => $this->lead->next_follow_up_at?->toDateString(),
        ];
    }
}
