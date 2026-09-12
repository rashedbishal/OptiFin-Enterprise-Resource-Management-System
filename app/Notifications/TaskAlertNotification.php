<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Notifications\Notification;

/**
 * The bell-notification counterpart to Task::alertActive() — sent once per
 * day for as long as a task's due-date alert stays active (see
 * App\Console\Commands\SendTaskAlertNotifications), so an ignored task keeps
 * resurfacing in the bell. `name` (not `title`) is deliberate: it lets
 * NotificationBell::present() reuse its existing dateReminderMessage()
 * helper unmodified — that helper already reads $data['name'], the same key
 * MeetingAlertNotification/FollowUpAlertNotification use for the lead's own
 * name.
 */
class TaskAlertNotification extends Notification
{
    public function __construct(private readonly Task $task) {}

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
            'due_date' => $this->task->due_date?->toDateString(),
            'due_time' => $this->task->dueTimeFormatted(),
        ];
    }
}
