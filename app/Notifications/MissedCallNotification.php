<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * WhatsApp inbound calling — "a customer called and the business did not serve
 * the call" (a LeadCall that ended Missed), shown in the bell. Sent to a tenant's
 * Client Admins by App\Jobs\SendMissedCallNotification, which is also what makes
 * it idempotent: it looks for an existing notification carrying the same
 * `call_id` before sending, so a queue retry never duplicates one.
 *
 * The payload is deliberately minimal and safe — the call's own id, the lead id
 * (NotificationUrlResolver's click-through key) and the lead's name. Never a
 * phone number, SDP, credential or any Meta text.
 */
class MissedCallNotification extends Notification
{
    public function __construct(
        private readonly string $callId,
        private readonly string $leadId,
        private readonly ?string $leadName,
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
            'call_id' => $this->callId,
            'lead_id' => $this->leadId,
            'name' => $this->leadName,
        ];
    }
}
