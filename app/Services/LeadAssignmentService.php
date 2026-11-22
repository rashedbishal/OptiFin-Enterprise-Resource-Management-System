<?php

namespace App\Services;

use App\Enums\AutoDistributionMethod;
use App\Enums\LeadDistributionMode;
use App\Enums\TenantUserType;
use App\Events\LeadAssigned;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\Tenant;
use App\Models\TenantUser;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeadAssignmentService
{
    /**
     * Assign a lead to a tenant user, recording the assignment history.
     *
     * M1 fix: the three writes below are wrapped in one transaction so a
     * failure partway through (e.g. the LeadAssignment insert) can never
     * leave the lead marked assigned with no matching history row.
     *
     * The LeadAssigned event is fired via DB::afterCommit() rather than
     * called directly: autoAssign() now wraps its call to this method in
     * its own outer transaction (see autoAssign()'s docblock, the M1
     * fairness-race fix), and this method's own transaction becomes a
     * nested savepoint when called from inside it — the same nesting
     * Index::applyBulkAssign() already relies on. DB::afterCommit() defers
     * the event until the outermost transaction genuinely commits
     * regardless of how many savepoints are nested inside it, so a queued
     * listener (SendLeadAssignedNotification) can never observe
     * not-yet-durable data. When this method's own transaction IS the
     * outermost one (every manual-assignment caller), the transaction has
     * already fully committed by the time this line runs, so
     * DB::afterCommit() fires the event immediately — identical timing to
     * the previous direct event() call.
     */
    public function assign(Lead $lead, TenantUser $assignee, ?TenantUser $assignedBy, ?string $note = null): void
    {
        DB::transaction(function () use ($lead, $assignee, $assignedBy, $note): void {
            $lead->update([
                'assigned_to' => $assignee->id,
                'assigned_at' => now(),
            ]);

            LeadAssignment::create([
                'lead_id' => $lead->id,
                'assigned_to' => $assignee->id,
                'assigned_by' => $assignedBy?->id,
                'assignment_note' => $note,
            ]);

            $assignee->update(['last_assigned_at' => now()]);
        });

        DB::afterCommit(fn () => event(new LeadAssigned($lead, $assignee, $assignedBy)));
    }

    /**
     * Clears a lead's assignment. Unlike assign(), this doesn't write a
     * LeadAssignment history row (that table requires a real assignee) or
     * fire LeadAssigned (nobody to notify) — the caller is responsible for
     * broadcasting LeadUpdated if the change needs to reach other viewers.
     */
    public function unassign(Lead $lead): void
    {
        $lead->update([
            'assigned_to' => null,
            'assigned_at' => null,
        ]);
    }

    /**
     * Automatically assign a newly received lead based on its tenant's lead
     * distribution settings. Does nothing if distribution is manual or
     * there are no eligible users to assign to.
     *
     * M1 fairness-race fix: candidate selection (pickRoundRobinUser()/
     * pickLeastLoadedUser()) and the assignment write (assign()) are now
     * wrapped in one DB::transaction() — previously the picking query ran
     * unguarded, outside any transaction, before assign() opened its own
     * separate one, so two near-simultaneous auto-assignments (e.g. two
     * webhook-created leads processed by two concurrent queue workers)
     * could both read the same "least recently assigned"/"least loaded"
     * user before either commit, sending both leads to the same person.
     * `attempts: 3` lets Laravel automatically retry the whole closure if
     * PostgreSQL reports a deadlock — a narrow but real possibility given
     * this transaction locks a TenantUser row then a Lead row, while manual
     * assignment (assign()'s other callers) locks a Lead row then a
     * TenantUser row; retrying is simpler and safer than redesigning lock
     * order for a collision this rare. pickRandomUser() is deliberately
     * unlocked (see its own docblock) but still runs inside this
     * transaction for structural uniformity — random has no ordering
     * guarantee to protect, so this costs nothing beyond participating in
     * an otherwise-uncontended transaction.
     */
    public function autoAssign(Lead $lead): void
    {
        $tenant = $lead->tenant;

        if (! $tenant instanceof Tenant || $tenant->lead_distribution_mode !== LeadDistributionMode::Automatic) {
            return;
        }

        DB::transaction(function () use ($lead, $tenant): void {
            // AutoAssignLead is queued, so a Client Admin may assign the lead
            // by hand (or the lead may be merged away) before the worker
            // runs, and a retried job would run this twice. Re-read the row
            // under a lock and only fill an assignment that is still empty —
            // never overwrite a human's choice or a merged lead.
            $current = Lead::query()->whereKey($lead->id)->lockForUpdate()->first(['id', 'assigned_to', 'merged_into_lead_id']);

            if ($current === null || $current->assigned_to !== null || $current->merged_into_lead_id !== null) {
                return;
            }

            $assignee = match ($tenant->auto_distribution_method) {
                AutoDistributionMethod::LeastLoaded => $this->pickLeastLoadedUser($tenant),
                AutoDistributionMethod::Random => $this->pickRandomUser($tenant),
                default => $this->pickRoundRobinUser($tenant),
            };

            if (! $assignee instanceof TenantUser) {
                return;
            }

            $this->assign($lead, $assignee, null, 'Automatically assigned.');
        }, 3);
    }

    /**
     * Runs $query twice, both times with lockForUpdate() already applied by
     * the caller, and returns only the second result. Only called from
     * inside autoAssign()'s transaction (see its docblock).
     *
     * Why twice: under PostgreSQL's default READ COMMITTED isolation, a
     * `SELECT ... ORDER BY ... LIMIT 1 FOR UPDATE` that blocks on an
     * already-locked candidate row does NOT re-rank against fresh data once
     * unblocked — it only re-checks that the SAME row still satisfies the
     * WHERE clause, and returns it regardless of whether a different row
     * would now rank first. Verified directly against a real PostgreSQL
     * connection: Worker B's query, blocked on Worker A's locked (and
     * least-recently-assigned) candidate, still returned that same
     * candidate after Worker A committed — even though Worker A's commit
     * had just made a DIFFERENT candidate the correct pick. A second,
     * separate execution of the identical query, run immediately after the
     * first returns, takes a fresh READ COMMITTED snapshot that correctly
     * reflects whatever committed while the first execution was blocked —
     * confirmed empirically to return the correct candidate. In the
     * uncontended case (the overwhelming majority of calls) both
     * executions return the same row, at the cost of one extra indexed
     * query.
     *
     * @param  Closure(): Builder<TenantUser>  $query
     */
    private function selectWithFreshLock(Closure $query): ?TenantUser
    {
        $query()->first();

        return $query()->first();
    }

    private function pickRoundRobinUser(Tenant $tenant): ?TenantUser
    {
        return $this->selectWithFreshLock(fn (): Builder => $this->eligibleUsers($tenant)
            ->orderByRaw('last_assigned_at IS NOT NULL')
            ->orderBy('last_assigned_at')
            ->lockForUpdate());
    }

    private function pickLeastLoadedUser(Tenant $tenant): ?TenantUser
    {
        return $this->selectWithFreshLock(fn (): Builder => $this->eligibleUsers($tenant)
            ->withCount(['assignedLeads' => function (Builder $query): void {
                // whereNull('merged_into_lead_id'): a lead merged away (see
                // App\Services\LeadMergeService) is retired, not part of
                // anyone's active workload — without this, "least loaded"
                // would keep counting a ghost lead against whoever it was
                // assigned to forever, unfairly deprioritizing them in
                // future auto-distribution.
                $query->whereNull('merged_into_lead_id')->whereHas('status', fn (Builder $q) => $q->where('is_closed', false));
            }])
            ->orderBy('assigned_leads_count')
            ->orderByRaw('last_assigned_at IS NOT NULL')
            ->orderBy('last_assigned_at')
            ->lockForUpdate());
    }

    /**
     * Deliberately never locked, unlike pickRoundRobinUser()/
     * pickLeastLoadedUser() above — random distribution has no ordering
     * guarantee to protect, so two concurrent random picks landing on the
     * same user is an acceptable, unremarkable outcome, not a fairness bug.
     */
    private function pickRandomUser(Tenant $tenant): ?TenantUser
    {
        return $this->eligibleUsers($tenant)->inRandomOrder()->first();
    }

    /**
     * Requires lead-management `update`, not just `view`: LeadPolicy::update()
     * (and CallRouter) gate working a lead on `update`, so a view-only user
     * auto-assigned a lead could see it but never act on it.
     *
     * @return Builder<TenantUser>
     */
    private function eligibleUsers(Tenant $tenant): Builder
    {
        return TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_type', TenantUserType::ClientUser)
            ->where('is_active', true)
            ->whereHas('role', function (Builder $query): void {
                $query->whereHas('modules', fn (Builder $q) => $q->where('slug', 'lead-management'))
                    ->whereHas('modulePermissions', function (Builder $q): void {
                        $q->whereHas('module', fn (Builder $q) => $q->where('slug', 'lead-management'))
                            ->whereHas('permission', fn (Builder $q) => $q->where('slug', 'update'));
                    });
            });
    }
}
