<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Notifications\Notification;

/**
 * Feature #9 — the bell-notification counterpart to
 * App\Console\Commands\NurtureInactiveLeads's daily inactivity sweep. Fires
 * at most once per lead per recipient per day (see that command's
 * alreadyNotifiedToday()-style dedup, the same pattern
 * MeetingAlertNotification/FollowUpAlertNotification already use) — not a
 * one-time notification, since a lead that stays inactive is eligible again
 * on every subsequent day's sweep.
 */
class LeadInactivityAlertNotification extends Notification
{
    public function __construct(private readonly Lead $lead, private readonly int $daysInactive) {}

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
            'days_inactive' => $this->daysInactive,
        ];
    }
}
