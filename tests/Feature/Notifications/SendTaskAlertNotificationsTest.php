<?php

use App\Enums\TaskStatus;
use App\Enums\TenantStatus;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\TaskAlertNotification;
use Illuminate\Support\Facades\DB;

test('a suspended tenant is skipped entirely, even with alerts enabled and a due task', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 2, 'status' => TenantStatus::Suspended]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->addDays(2)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications')->assertSuccessful();

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a client admin is notified once a task\'s due-date alert window opens', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 2]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->addDays(2)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications')->assertSuccessful();

    expect($clientAdmin->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(1);
});

test('a task outside its alert window does not notify anyone', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 2]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->addDays(10)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a disabled task alert setting sends nothing even for a due task', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => false, 'task_alert_days_before' => 2]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->toDateString()]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('only the task\'s own assignee and admins are notified, not the lead\'s unrelated assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 1]);
    $leadAssignee = clientUserWithLeadPermission($tenant);
    $taskAssignee = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $leadAssignee->id]);
    Task::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'due_date' => now()->addDay()->toDateString(),
        'assigned_to' => $taskAssignee->id,
    ]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(1);
    expect($taskAssignee->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(1);
    expect($leadAssignee->notifications()->count())->toBe(0);
});

test('running the sweep twice in the same day does not duplicate a notification', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 0]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->toDateString()]);

    $this->artisan('tasks:send-alert-notifications');
    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(1);
});

test('a task with no due date is never alerted on', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 5]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => null]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('a completed task is never alerted on even with a past-due date', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 5]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->completed()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->subDays(3)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->count())->toBe(0);
});

test('an overdue pending task keeps alerting, not just on the exact due day', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 0]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    Task::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->subDays(3)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications');

    expect($clientAdmin->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(1);
});

// ── REL-4 — chunkById() pagination regression coverage ─────────────────────
//
// sweep()'s Task-level query uses chunkById(200, ...) rather than each().
// The tests below exercise that exact query shape directly (same
// tenant/status/due-date filters sweep() itself uses) so a genuine
// multi-page run, and a row disappearing between pages, can be
// deterministically controlled and observed — something the full
// `tasks:send-alert-notifications` command has no seam to intercept
// mid-run. sweep()'s own notify/dedup callback logic is already fully
// covered by the tests above, unmodified.

test('the sweep notifies every qualifying task across more than one chunkById page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['task_alert_enabled' => true, 'task_alert_days_before' => 2]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    // 201 tasks — one more than the production chunkById(200, ...) page
    // size, forcing a genuine second page fetch.
    Task::factory()->count(201)->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->addDays(2)->toDateString()]);

    $this->artisan('tasks:send-alert-notifications')->assertSuccessful();

    // Every one of the 201 tasks across both pages produced its own
    // notification — none silently dropped at the page boundary.
    expect($clientAdmin->notifications()->where('type', TaskAlertNotification::class)->count())->toBe(201);
});

test('deleting an already-processed task mid-sweep does not skip a later qualifying task (REL-4 regression)', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $tasks = Task::factory()->count(4)->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->addDays(2)->toDateString()])
        ->sortBy('id')->values();

    $processedIds = [];
    $pageNumber = 0;

    // Mirrors sweep()'s exact Task-level query shape and filters.
    Task::query()
        ->where('tenant_id', $tenant->id)
        ->where('status', TaskStatus::Pending->value)
        ->whereNotNull('due_date')
        ->with('assignee')
        ->chunkById(2, function ($page) use (&$processedIds, &$pageNumber): void {
            $pageNumber++;

            foreach ($page as $task) {
                $processedIds[] = $task->id;
            }

            // Simulate a Client Admin deleting a task from the UI between
            // pages — the exact REL-4 failure trigger — mid-sweep. Already
            // recorded above before this delete, so still expected below.
            if ($pageNumber === 1) {
                $page->first()->delete();
            }
        });

    // All 4 original tasks are accounted for — none silently dropped.
    expect($processedIds)->toBe($tasks->pluck('id')->values()->all());
});

test('a task completed between pages is excluded without skipping a later qualifying task, tenant isolation and eager loading preserved (REL-4 regression)', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $otherLead = Lead::factory()->create(['tenant_id' => $otherTenant->id]);

    $tasks = Task::factory()->count(4)->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'due_date' => now()->addDays(2)->toDateString()])
        ->sortBy('id')->values();
    Task::factory()->count(2)->create(['tenant_id' => $otherTenant->id, 'lead_id' => $otherLead->id, 'due_date' => now()->addDays(2)->toDateString()]);

    $processedIds = [];
    $pageNumber = 0;

    DB::enableQueryLog();
    Task::query()
        ->where('tenant_id', $tenant->id)
        ->where('status', TaskStatus::Pending->value)
        ->whereNotNull('due_date')
        ->with('assignee')
        ->chunkById(2, function ($page) use (&$processedIds, &$pageNumber): void {
            $pageNumber++;

            foreach ($page as $task) {
                $processedIds[] = $task->id;
                // assignee was eager-loaded above — accessing it here must
                // not trigger an additional query per row.
                $task->assignee;
            }

            // Complete the first task of page 1 — the same
            // where('status', Pending) disqualification sweep() itself
            // relies on. Already recorded above before this update, so
            // still expected below.
            if ($pageNumber === 1) {
                $page->first()->update(['status' => TaskStatus::Completed->value]);
            }
        });
    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    // All 4 original tasks are accounted for — none silently dropped.
    expect($processedIds)->toBe($tasks->pluck('id')->values()->all());

    // 2 page-fetch queries (page 1 of 2, page 2 of 1 remaining) + up to 2
    // eager-load queries for assignee (one per page) + the status update —
    // no per-row N+1 from accessing assignee above.
    $selectQueries = collect($queryLog)->filter(fn (array $q): bool => str_starts_with(trim($q['query']), 'select'));
    expect($selectQueries->count())->toBeLessThanOrEqual(4);
});
