<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\TenantUser;
use Illuminate\Notifications\Notification;

/**
 * Sent to a Task's assignee when they're assigned — on creation with an
 * assignee already set, or when an existing Task is reassigned to someone
 * new (see Show::saveTask()). Never sent when the assignee hasn't actually
 * changed. `lead_id` in the payload is what gives this notification working
 * click-through for free via App\Services\NotificationUrlResolver, which
 * resolves generically off that key regardless of notification type.
 */
class TaskAssignedNotification extends Notification
{
    public function __construct(
        private readonly Task $task,
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
            'task_id' => $this->task->id,
            'lead_id' => $this->task->lead_id,
            'name' => $this->task->title,
            'assigned_by' => $this->assignedBy?->name,
        ];
    }
}
