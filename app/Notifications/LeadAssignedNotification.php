<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class LeadAssignedNotification extends Notification
{
    public function __construct(
        private readonly Lead $lead,
        private readonly ?TenantUser $assignedBy,
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
            'assigned_by' => $this->assignedBy?->name,
            'message' => Str::limit((string) $this->lead->message, 120),
        ];
    }
}
