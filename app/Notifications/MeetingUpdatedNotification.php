<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Notifications\Notification;

/**
 * Sent to every other active Client Admin (see App\Services\LeadAdminNotifier)
 * when a lead's meeting date is set for the first time or changed to a
 * different date — from either the New Lead form (App\Livewire\Tenant\Leads\Index)
 * or the Deal form on the lead detail page (App\Livewire\Tenant\Leads\Show).
 * $rescheduled distinguishes the two cases for NotificationBell::present()'s
 * wording ("scheduled" vs "rescheduled") — clearing the date entirely is a
 * cancellation, handled by MeetingCancelledNotification instead.
 */
class MeetingUpdatedNotification extends Notification
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
            'meeting_date' => $this->lead->meeting_date?->toDateString(),
            'meeting_time' => $this->lead->meetingTimeFormatted(),
        ];
    }
}
