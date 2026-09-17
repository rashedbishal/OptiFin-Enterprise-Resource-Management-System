<?php

namespace App\Console\Commands;

use App\Enums\LeadPriority;
use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use App\Enums\TaskStatus;
use App\Enums\TenantStatus;
use App\Enums\TenantUserType;
use App\Models\Lead;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\LeadInactivityAlertNotification;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Feature #9 Phase 1 — lead-inactivity nurturing. Daily sweep, single
 * condition (App\Enums\LeadScoringField::DaysSinceLastStatusChange's
 * existing resolution, reused as-is — LeadScoringRule is never touched),
 * two independently-toggleable actions (notify, create a follow-up task).
 * Structurally the same shape as SendLeadAlertNotifications/
 * SendTaskAlertNotifications: iterate active tenants explicitly, filter
 * every lead query by that tenant's tenant_id explicitly (never
 * BelongsToTenant's global scope, which doesn't activate in this context).
 */
#[Signature('leads:nurture-inactive-leads')]
#[Description('Notify admins/assignees and optionally create a follow-up task for leads inactive past the tenant-configured threshold')]
class NurtureInactiveLeads extends Command
{
    public function handle(): int
    {
        Tenant::query()
            ->where('status', TenantStatus::Active)
            ->where(function ($query): void {
                $query->where('lead_nurturing_notify_enabled', true)
                    ->orWhere('lead_nurturing_task_enabled', true);
            })
            ->each(function (Tenant $tenant): void {
                try {
                    $this->sweepTenant($tenant);
                } catch (Throwable $e) {
                    Log::error("Lead nurturing sweep failed for tenant [{$tenant->id}]: {$e->getMessage()}");
                }
            });

        return self::SUCCESS;
    }

    private function sweepTenant(Tenant $tenant): void
    {
        $closedStatusIds = $tenant->leadStatuses()->where('is_closed', true)->pluck('lead_statuses.id');

        // TenantUser::where('tenant_id', ...) rather than $tenant->tenantUsers()
        // — equivalent (that relation is hasMany(TenantUser::class), the
        // default foreign key is the same tenant_id) but keeps $admins
        // provably Collection<int, TenantUser> for static analysis, since
        // Tenant::tenantUsers()'s own return type doesn't declare its
        // generics (a pre-existing, already-accepted gap).
        $admins = TenantUser::where('tenant_id', $tenant->id)
            ->where('user_type', TenantUserType::ClientAdmin)
            ->where('is_active', true)
            ->get();

        // REL-4 — chunkById(), not each(): see SendLeadAlertNotifications::
        // sweep()'s identical comment for the full reasoning (each()'s
        // underlying chunk() uses OFFSET pagination, which can silently
        // skip a lead if an earlier lead is deleted/merged away between
        // pages; chunkById() resumes from the last-seen lead's own id
        // instead, immune to that shift).
        Lead::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('merged_into_lead_id')
            ->whereNotIn('status_id', $closedStatusIds)
            ->with('assignedUser')
            ->chunkById(200, function ($leads) use ($tenant, $admins): void {
                foreach ($leads as $lead) {
                    try {
                        $this->processLead($tenant, $lead, $admins);
                    } catch (Throwable $e) {
                        Log::error("Lead nurturing action failed for lead [{$lead->id}]: {$e->getMessage()}");
                    }
                }
            });
    }

    /**
     * @param  Collection<int, TenantUser>  $admins
     */
    private function processLead(Tenant $tenant, Lead $lead, Collection $admins): void
    {
        $daysInactive = LeadScoringField::DaysSinceLastStatusChange->resolve($lead);

        if ($daysInactive === null) {
            return;
        }

        $isInactive = LeadScoringOperator::GreaterThanOrEqual->evaluate($daysInactive, $tenant->lead_nurturing_inactive_days);

        if (! $isInactive) {
            return;
        }

        if ($tenant->lead_nurturing_notify_enabled) {
            try {
                $this->notifyRecipients($lead, $admins, $daysInactive);
            } catch (Throwable $e) {
                Log::error("Lead nurturing notification failed for lead [{$lead->id}]: {$e->getMessage()}");
            }
        }

        if ($tenant->lead_nurturing_task_enabled) {
            try {
                $this->createFollowUpTask($tenant, $lead, $daysInactive);
            } catch (Throwable $e) {
                Log::error("Lead nurturing task creation failed for lead [{$lead->id}]: {$e->getMessage()}");
            }
        }
    }

    /**
     * Every active Client Admin (tenant-wide visibility) plus the lead's own
     * active assignee if any and not already in that set — the identical
     * recipient rule SendLeadAlertNotifications::recipients() already uses.
     * Deduped per recipient against today's already-sent notifications of
     * this same class/lead, same shape as alreadyNotifiedToday().
     *
     * @param  Collection<int, TenantUser>  $admins
     */
    private function notifyRecipients(Lead $lead, Collection $admins, int $daysInactive): void
    {
        $recipients = $admins->toBase();

        $assignee = $lead->assignedUser;

        if ($assignee instanceof TenantUser && $assignee->is_active && ! $recipients->contains('id', $assignee->id)) {
            $recipients->push($assignee);
        }

        foreach ($recipients as $recipient) {
            if ($this->alreadyNotifiedToday($recipient, $lead)) {
                continue;
            }

            $recipient->notify(new LeadInactivityAlertNotification($lead, $daysInactive));
        }
    }

    /**
     * A >= today / < tomorrow range rather than whereDate('created_at', ...)
     * — matching SendLeadAlertNotifications::alreadyNotifiedToday() — since
     * DATE()-wrapping an indexed timestamp column prevents a plain range
     * index from being used.
     *
     * Deliberately NOT SendLeadAlertNotifications's own `where('data->lead_id',
     * ...)` JSON-path syntax: the real `notifications.data` column is a
     * plain `text` column (Laravel's default notifications migration), not
     * `json`/`jsonb` — verified directly against this app's actual Postgres
     * database, `->where('data->lead_id', ...)` throws
     * `SQLSTATE[42883]: operator does not exist: text ->> unknown` at
     * runtime, every time. This is a real, pre-existing latent bug in
     * SendLeadAlertNotifications/SendTaskAlertNotifications's identical
     * pattern (confirmed by reproducing it against the exact same query
     * those commands run) — out of scope to fix here (see the Feature #9
     * implementation report), but not copied into this new command. A
     * `LIKE` match against the JSON-encoded text is portable across
     * Postgres and SQLite and doesn't require the column to be JSON-typed.
     */
    private function alreadyNotifiedToday(TenantUser $recipient, Lead $lead): bool
    {
        return $recipient->notifications()
            ->where('type', LeadInactivityAlertNotification::class)
            ->where('data', 'like', '%"lead_id":"'.$lead->id.'"%')
            ->where('created_at', '>=', today())
            ->where('created_at', '<', today()->addDay())
            ->exists();
    }

    /**
     * Idempotency note: the single command instance (guarded by
     * withoutOverlapping() in routes/console.php) processes every tenant's
     * leads strictly sequentially within one PHP process — there is no
     * concurrent writer that could race between this check and its update,
     * so a plain "check, then write" is sufficient here without a row lock.
     * This would need revisiting only if this command were ever made to run
     * concurrently (e.g. queued per-tenant) or the check/act pair were split
     * across separate requests, neither of which is the case for this
     * scheduled, single-process sweep.
     */
    private function createFollowUpTask(Tenant $tenant, Lead $lead, int $daysInactive): void
    {
        if ($lead->nurturing_task_created_at !== null && $lead->nurturing_task_created_at->greaterThanOrEqualTo(today())) {
            return;
        }

        $task = Task::create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'status' => TaskStatus::Pending,
            'title' => __('Follow up — inactive for :days days', ['days' => $daysInactive]),
            'due_date' => today(),
            'priority' => LeadPriority::Medium,
            'assigned_to' => $lead->assigned_to,
            'created_by' => null,
        ]);

        // Raw query builder update, not $lead->update() — never touches
        // updated_at (see Lead.php's own note next to this column's cast),
        // matching the same discipline LeadScoringService's score writes and
        // Lead::markUpdateSeen() already follow.
        DB::table('leads')->where('id', $lead->id)->update(['nurturing_task_created_at' => now()]);

        if ($task->assigned_to !== null) {
            $assignee = TenantUser::where('tenant_id', $tenant->id)->find($task->assigned_to);
            $assignee?->notify(new TaskAssignedNotification($task, null));
        }
    }
}
