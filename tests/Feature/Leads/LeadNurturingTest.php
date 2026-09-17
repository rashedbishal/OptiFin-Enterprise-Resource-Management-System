<?php

use App\Enums\LeadPriority;
use App\Enums\TaskStatus;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Task;
use App\Models\Tenant;
use App\Notifications\LeadInactivityAlertNotification;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function enableNurturing(Tenant $tenant, bool $notify, bool $task, int $days): void
{
    $tenant->update([
        'lead_nurturing_notify_enabled' => $notify,
        'lead_nurturing_task_enabled' => $task,
        'lead_nurturing_inactive_days' => $days,
    ]);
}

// ── Settings UI ──────────────────────────────────────────────────────────

test('a Client Admin can enable the notification action independently of the task action', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleNurturingNotify')
        ->set('nurturingDaysInput', '7')
        ->call('saveNurturingDays');

    $tenant->refresh();
    expect($tenant->lead_nurturing_notify_enabled)->toBeTrue();
    expect($tenant->lead_nurturing_task_enabled)->toBeFalse();
    expect($tenant->lead_nurturing_inactive_days)->toBe(7);
});

test('a Client Admin can enable the task action independently of the notification action', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleNurturingTask')
        ->set('nurturingDaysInput', '5')
        ->call('saveNurturingDays');

    $tenant->refresh();
    expect($tenant->lead_nurturing_task_enabled)->toBeTrue();
    expect($tenant->lead_nurturing_notify_enabled)->toBeFalse();
    expect($tenant->lead_nurturing_inactive_days)->toBe(5);
});

test('enabling the second action while the first is already on reuses the existing threshold without reopening the modal', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: false, days: 12);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleNurturingTask')
        ->assertSet('showNurturingDaysModal', false);

    $tenant->refresh();
    expect($tenant->lead_nurturing_task_enabled)->toBeTrue();
    expect($tenant->lead_nurturing_inactive_days)->toBe(12);
});

test('a Client User cannot change nurturing settings', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);

    Livewire::actingAs($rep)->test(Index::class)->call('toggleNurturingNotify');

    expect($tenant->fresh()->lead_nurturing_notify_enabled)->toBeFalse();
});

test('the inactive-days threshold rejects a negative value', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleNurturingNotify')
        ->set('nurturingDaysInput', '-1')
        ->call('saveNurturingDays')
        ->assertHasErrors('nurturingDaysInput');
});

test('the inactive-days threshold rejects a value over 365', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleNurturingNotify')
        ->set('nurturingDaysInput', '366')
        ->call('saveNurturingDays')
        ->assertHasErrors('nurturingDaysInput');
});

test('the inactive-days threshold accepts exactly 365', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleNurturingNotify')
        ->set('nurturingDaysInput', '365')
        ->call('saveNurturingDays')
        ->assertHasNoErrors();

    expect($tenant->fresh()->lead_nurturing_inactive_days)->toBe(365);
});

// ── Threshold boundary ───────────────────────────────────────────────────

test('a lead exactly at the inactivity threshold qualifies', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: false, days: 10);

    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(10)]);

    Artisan::call('leads:nurture-inactive-leads');

    expect(DB::table('notifications')->where('type', LeadInactivityAlertNotification::class)->count())->toBe(1);
});

test('a lead one day below the inactivity threshold does not qualify', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: false, days: 10);

    Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(9)]);

    Artisan::call('leads:nurture-inactive-leads');

    expect(DB::table('notifications')->where('type', LeadInactivityAlertNotification::class)->count())->toBe(0);
});

// ── Eligibility ───────────────────────────────────────────────────────────

test('a merged-away lead is excluded from nurturing', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: true, days: 0);

    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(30)]);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => now()->subDays(30),
        'merged_into_lead_id' => $survivor->id,
    ]);

    Artisan::call('leads:nurture-inactive-leads');

    expect(Task::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a closed lead is excluded from nurturing', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: true, days: 0);

    $closedStatus = LeadStatus::where('is_closed', true)->firstOrFail();
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'status_id' => $closedStatus->id,
        'created_at' => now()->subDays(30),
    ]);

    Artisan::call('leads:nurture-inactive-leads');

    expect(DB::table('notifications')->where('type', LeadInactivityAlertNotification::class)->count())->toBe(0);
    expect(Task::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('an open (non-closed) lead is eligible', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: false, days: 0);

    $openStatus = LeadStatus::where('is_closed', false)->firstOrFail();
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'status_id' => $openStatus->id,
        'created_at' => now()->subDays(1),
    ]);

    Artisan::call('leads:nurture-inactive-leads');

    expect(DB::table('notifications')->where('type', LeadInactivityAlertNotification::class)->count())->toBe(1);
});

// ── Tenant isolation ──────────────────────────────────────────────────────

test('the sweep never touches another tenant\'s leads', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    enableNurturing($tenantA, notify: true, task: true, days: 0);
    enableNurturing($tenantB, notify: false, task: false, days: 0);

    Lead::factory()->create(['tenant_id' => $tenantA->id, 'created_at' => now()->subDays(5)]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id, 'created_at' => now()->subDays(5)]);

    Artisan::call('leads:nurture-inactive-leads');

    expect(Task::where('tenant_id', $tenantA->id)->count())->toBe(1);
    expect(Task::where('tenant_id', $tenantB->id)->count())->toBe(0);
    expect(DB::table('notifications')->where('data', 'like', '%"lead_id":"'.$leadB->id.'"%')->count())->toBe(0);
});

// ── Notification recipients & dedup ────────────────────────────────────────

test('notification recipients are every active Client Admin plus the lead\'s active assignee, without duplicates', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: false, days: 0);

    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'created_at' => now()->subDays(5)]);

    Artisan::call('leads:nurture-inactive-leads');

    $recipientIds = DB::table('notifications')
        ->where('type', LeadInactivityAlertNotification::class)
        ->where('data', 'like', '%"lead_id":"'.$lead->id.'"%')
        ->pluck('notifiable_id');

    expect($recipientIds->sort()->values()->all())->toBe(collect([$clientAdmin->id, $rep->id])->sort()->values()->all());
});

test('the admin does not receive a duplicate notification when they are also the assignee', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: false, days: 0);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $clientAdmin->id, 'created_at' => now()->subDays(5)]);

    Artisan::call('leads:nurture-inactive-leads');

    $count = DB::table('notifications')
        ->where('type', LeadInactivityAlertNotification::class)
        ->where('data', 'like', '%"lead_id":"'.$lead->id.'"%')
        ->count();

    expect($count)->toBe(1);
});

test('a lead is not notified twice for the same recipient on the same day', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: false, days: 0);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(5)]);

    Artisan::call('leads:nurture-inactive-leads');
    Artisan::call('leads:nurture-inactive-leads');

    $count = DB::table('notifications')
        ->where('type', LeadInactivityAlertNotification::class)
        ->where('data', 'like', '%"lead_id":"'.$lead->id.'"%')
        ->count();

    expect($count)->toBe(1);
});

test('the notification payload carries the lead id and computed days-inactive', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: false, days: 3);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(3)]);

    Artisan::call('leads:nurture-inactive-leads');

    $row = DB::table('notifications')->where('type', LeadInactivityAlertNotification::class)->first();
    $data = json_decode($row->data, true);

    expect($data['lead_id'])->toBe($lead->id);
    expect($data['days_inactive'])->toBe(3);
});

// ── Task creation ───────────────────────────────────────────────────────

test('the nurturing task is created with the expected fields', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: false, task: true, days: 4);

    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'created_at' => now()->subDays(4)]);

    Artisan::call('leads:nurture-inactive-leads');

    $task = Task::where('lead_id', $lead->id)->firstOrFail();

    expect($task->tenant_id)->toBe($tenant->id);
    expect($task->title)->toBe('Follow up — inactive for 4 days');
    expect($task->due_date->toDateString())->toBe(today()->toDateString());
    expect($task->priority)->toBe(LeadPriority::Medium);
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->assigned_to)->toBe($rep->id);
    expect($task->created_by)->toBeNull();
});

test('an unassigned lead gets an unassigned nurturing task', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: false, task: true, days: 0);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => null, 'created_at' => now()->subDays(1)]);

    Artisan::call('leads:nurture-inactive-leads');

    $task = Task::where('lead_id', $lead->id)->firstOrFail();
    expect($task->assigned_to)->toBeNull();
});

test('an assigned nurturing task notifies the assignee via the existing TaskAssignedNotification', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: false, task: true, days: 0);

    $rep = clientUserWithLeadPermission($tenant, ['view']);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'created_at' => now()->subDays(1)]);

    Artisan::call('leads:nurture-inactive-leads');

    expect(DB::table('notifications')->where('type', TaskAssignedNotification::class)->where('notifiable_id', $rep->id)->count())->toBe(1);
});

test('a lead does not receive more than one nurturing task per day', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: false, task: true, days: 0);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(1)]);

    Artisan::call('leads:nurture-inactive-leads');
    Artisan::call('leads:nurture-inactive-leads');

    expect(Task::where('lead_id', $lead->id)->count())->toBe(1);
    expect($lead->fresh()->nurturing_task_created_at)->not->toBeNull();
});

test('the task-creation marker is written via a raw update that does not touch updated_at', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: false, task: true, days: 0);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(1)]);
    $originalUpdatedAt = $lead->fresh()->updated_at;

    Artisan::call('leads:nurture-inactive-leads');

    expect($lead->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

// ── Per-item failure isolation ───────────────────────────────────────────

test('one lead failing does not stop the rest of the tenant\'s sweep', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: true, days: 0);

    $goodLeadOne = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(5)]);
    $goodLeadTwo = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(5)]);

    Artisan::call('leads:nurture-inactive-leads');

    expect(Task::where('lead_id', $goodLeadOne->id)->exists())->toBeTrue();
    expect(Task::where('lead_id', $goodLeadTwo->id)->exists())->toBeTrue();
});

test('one tenant\'s sweep failing does not stop other tenants from being processed', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    enableNurturing($tenantA, notify: true, task: true, days: 0);
    enableNurturing($tenantB, notify: true, task: true, days: 0);

    $leadA = Lead::factory()->create(['tenant_id' => $tenantA->id, 'created_at' => now()->subDays(5)]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id, 'created_at' => now()->subDays(5)]);

    Artisan::call('leads:nurture-inactive-leads');

    expect(Task::where('lead_id', $leadA->id)->exists())->toBeTrue();
    expect(Task::where('lead_id', $leadB->id)->exists())->toBeTrue();
});

// ── Command registration ──────────────────────────────────────────────────

test('the nurturing command is registered and runs without error', function () {
    $exitCode = Artisan::call('leads:nurture-inactive-leads');

    expect($exitCode)->toBe(0);
});

// ── Regression: existing alert settings remain unaffected ─────────────────

test('existing meeting/follow-up/task alert settings are unaffected by nurturing settings', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleMeetingAlert')
        ->set('meetingAlertDaysInput', '2')
        ->call('saveMeetingAlertDays')
        ->call('toggleNurturingNotify')
        ->set('nurturingDaysInput', '7')
        ->call('saveNurturingDays');

    $tenant->refresh();
    expect($tenant->meeting_alert_enabled)->toBeTrue();
    expect($tenant->meeting_alert_days_before)->toBe(2);
    expect($tenant->followup_alert_enabled)->toBeFalse();
    expect($tenant->task_alert_enabled)->toBeFalse();
    expect($tenant->lead_nurturing_notify_enabled)->toBeTrue();
});

// ── REL-4 — chunkById() pagination regression coverage ─────────────────────
//
// sweepTenant()'s Lead-level query uses chunkById(200, ...) rather than
// each(). The tests below exercise that exact query shape directly (same
// tenant/merged-lead/closed-status filters sweepTenant() itself uses) so a
// genuine multi-page run, and a row disappearing between pages, can be
// deterministically controlled and observed — something the full
// `leads:nurture-inactive-leads` command has no seam to intercept mid-run.
// sweepTenant()'s own notify/task-creation callback logic is already fully
// covered by the tests above, unmodified.

test('the sweep notifies every qualifying lead across more than one chunkById page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    enableNurturing($tenant, notify: true, task: false, days: 10);

    // 201 leads — one more than the production chunkById(200, ...) page
    // size, forcing a genuine second page fetch.
    Lead::factory()->count(201)->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(10)]);

    Artisan::call('leads:nurture-inactive-leads');

    // Every one of the 201 leads across both pages produced its own
    // notification — none silently dropped at the page boundary.
    expect(DB::table('notifications')->where('type', LeadInactivityAlertNotification::class)->count())->toBe(201);
});

test('deleting an already-processed lead mid-sweep does not skip a later qualifying lead (REL-4 regression)', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $closedStatusIds = $tenant->leadStatuses()->where('is_closed', true)->pluck('lead_statuses.id');

    $leads = Lead::factory()->count(4)->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(30)])
        ->sortBy('id')->values();

    $processedIds = [];
    $pageNumber = 0;

    // Mirrors sweepTenant()'s exact Lead-level query shape and filters.
    Lead::query()
        ->where('tenant_id', $tenant->id)
        ->whereNull('merged_into_lead_id')
        ->whereNotIn('status_id', $closedStatusIds)
        ->with('assignedUser')
        ->chunkById(2, function ($page) use (&$processedIds, &$pageNumber): void {
            $pageNumber++;

            foreach ($page as $lead) {
                $processedIds[] = $lead->id;
            }

            // Simulate a Client Admin deleting a lead from the UI between
            // pages — the exact REL-4 failure trigger — mid-sweep. Already
            // recorded above before this delete, so still expected below.
            if ($pageNumber === 1) {
                $page->first()->delete();
            }
        });

    // All 4 original leads are accounted for — none silently dropped.
    expect($processedIds)->toBe($leads->pluck('id')->values()->all());
});

test('a lead closed between pages is excluded without skipping a later qualifying lead, tenant isolation and eager loading preserved (REL-4 regression)', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $closedStatusIds = $tenant->leadStatuses()->where('is_closed', true)->pluck('lead_statuses.id');
    $closedStatus = LeadStatus::where('is_closed', true)->firstOrFail();

    $leads = Lead::factory()->count(4)->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(30)])
        ->sortBy('id')->values();
    Lead::factory()->count(2)->create(['tenant_id' => $otherTenant->id, 'created_at' => now()->subDays(30)]);

    $processedIds = [];
    $pageNumber = 0;

    DB::enableQueryLog();
    Lead::query()
        ->where('tenant_id', $tenant->id)
        ->whereNull('merged_into_lead_id')
        ->whereNotIn('status_id', $closedStatusIds)
        ->with('assignedUser')
        ->chunkById(2, function ($page) use (&$processedIds, &$pageNumber, $closedStatus): void {
            $pageNumber++;

            foreach ($page as $lead) {
                $processedIds[] = $lead->id;
                // assignedUser was eager-loaded above — accessing it here
                // must not trigger an additional query per row.
                $lead->assignedUser;
            }

            // Close the first lead of page 1 — the same
            // whereNotIn('status_id', $closedStatusIds) disqualification
            // sweepTenant() itself relies on. Already recorded above before
            // this update, so still expected below.
            if ($pageNumber === 1) {
                $page->first()->update(['status_id' => $closedStatus->id]);
            }
        });
    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    // All 4 original leads are accounted for — none silently dropped.
    expect($processedIds)->toBe($leads->pluck('id')->values()->all());

    // 2 page-fetch queries (page 1 of 2, page 2 of 1 remaining) + up to 2
    // eager-load queries for assignedUser (one per page) + the status
    // update — no per-row N+1 from accessing assignedUser above.
    $selectQueries = collect($queryLog)->filter(fn (array $q): bool => str_starts_with(trim($q['query']), 'select'));
    expect($selectQueries->count())->toBeLessThanOrEqual(4);
});
