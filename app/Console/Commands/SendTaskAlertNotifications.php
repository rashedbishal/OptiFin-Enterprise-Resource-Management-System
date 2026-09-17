<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Enums\TenantStatus;
use App\Enums\TenantUserType;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\TaskAlertNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The bell-notification counterpart to Task::alertActive() — a dedicated
 * command rather than a third sweep folded into
 * App\Console\Commands\SendLeadAlertNotifications, since that command's own
 * recipients() logic is built around the LEAD's assignee, while a Task has
 * its own independent assignee (see App\Models\Task's docblock) — different
 * enough to warrant its own command, copy-adapted from the same sweep/dedup
 * shape rather than entangled into the existing one. Runs once a day (see
 * routes/console.php), same cadence and same idempotency technique
 * (alreadyNotifiedToday(), no stored "last sent" column) as its sibling.
 */
#[Signature('tasks:send-alert-notifications')]
#[Description('Send bell notifications for tasks whose due-date alert is currently active')]
class SendTaskAlertNotifications extends Command
{
    public function handle(): int
    {
        Tenant::query()
            ->where('status', TenantStatus::Active)
            ->where('task_alert_enabled', true)
            ->each(function (Tenant $tenant): void {
                $this->sweep($tenant);
            });

        return self::SUCCESS;
    }

    private function sweep(Tenant $tenant): void
    {
        $admins = $tenant->tenantUsers()
            ->where('user_type', TenantUserType::ClientAdmin)
            ->where('is_active', true)
            ->get();

        // REL-4 — chunkById(), not each(): see SendLeadAlertNotifications::
        // sweep()'s identical comment for the full reasoning (each()'s
        // underlying chunk() uses OFFSET pagination, which can silently
        // skip a task if an earlier task is deleted/disqualified between
        // pages; chunkById() resumes from the last-seen task's own id
        // instead, immune to that shift).
        Task::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', TaskStatus::Pending->value)
            ->whereNotNull('due_date')
            ->with('assignee')
            ->chunkById(200, function ($tasks) use ($tenant, $admins): void {
                foreach ($tasks as $task) {
                    if (! $task->alertActive($tenant->task_alert_enabled, $tenant->task_alert_days_before)) {
                        continue;
                    }

                    foreach ($this->recipients($admins, $task) as $recipient) {
                        if ($this->alreadyNotifiedToday($recipient, $task)) {
                            continue;
                        }

                        $recipient->notify(new TaskAlertNotification($task));
                    }
                }
            });
    }

    /**
     * Every active Client Admin (they see alerts for every task in the
     * tenant, same visibility rule every other lead-management alert
     * follows) plus the task's own assignee if it has one and isn't already
     * in that set — NOT the lead's assignee, since a Task's ownership is
     * independent (see App\Models\Task's docblock).
     *
     * @param  Collection<int, TenantUser>  $admins
     * @return SupportCollection<int, TenantUser>
     */
    private function recipients(Collection $admins, Task $task): SupportCollection
    {
        $recipients = $admins->toBase();

        $assignee = $task->assignee;

        if ($assignee instanceof TenantUser && $assignee->is_active && ! $recipients->contains('id', $assignee->id)) {
            $recipients->push($assignee);
        }

        return $recipients;
    }

    /**
     * Guards against a duplicate for the same task/recipient/day — same
     * range-query technique (not whereDate(), see the identical reasoning
     * in SendLeadAlertNotifications) as its sibling command.
     */
    private function alreadyNotifiedToday(TenantUser $recipient, Task $task): bool
    {
        return $recipient->notifications()
            ->where('type', TaskAlertNotification::class)
            ->where('data', 'like', '%"task_id":"'.$task->id.'"%')
            ->where('created_at', '>=', today())
            ->where('created_at', '<', today()->addDay())
            ->exists();
    }
}
