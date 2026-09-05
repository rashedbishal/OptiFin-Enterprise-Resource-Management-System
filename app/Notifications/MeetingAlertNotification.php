<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Notifications\Notification;

/**
 * The bell-notification counterpart to Lead::meetingAlertActive()'s Kanban
 * card shimmer — sent once per day for as long as a lead's meeting alert
 * stays active (see App\Console\Commands\SendLeadAlertNotifications), so an
 * ignored meeting keeps resurfacing in the bell instead of only ever
 * showing as a shimmer someone has to notice on the board.
 */
class MeetingAlertNotification extends Notification
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
            'meeting_date' => $this->lead->meeting_date?->toDateString(),
            'meeting_time' => $this->lead->meetingTimeFormatted(),
        ];
    }
}
