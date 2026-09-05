<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Notifications\Notification;

/**
 * Sent to every other active Client Admin (never the person who cancelled
 * it — see Show::notifyOtherAdmins()) when a lead's scheduled meeting is
 * cancelled from the lead detail page. Constructed and sent before
 * Show::recordDateAlertAction() nulls the lead's meeting_date, so
 * toArray() below still sees the real date being cancelled.
 */
class MeetingCancelledNotification extends Notification
{
    public function __construct(
        private readonly Lead $lead,
        private readonly TenantUser $cancelledBy,
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
            'cancelled_by' => $this->cancelledBy->name,
            'meeting_date' => $this->lead->meeting_date?->toDateString(),
        ];
    }
}
