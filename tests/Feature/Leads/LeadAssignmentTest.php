<?php

use App\Enums\AutoDistributionMethod;
use App\Enums\LeadDistributionMode;
use App\Events\LeadAssigned;
use App\Events\LeadUpdated;
use App\Events\UserNotified;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadDetailUpdateLog;
use App\Models\TenantUser;
use App\Notifications\LeadAssignedNotification;
use App\Services\LeadAssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('manually assigning a lead updates it and records the assignment history', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(LeadAssignmentService::class)->assign($lead, $rep, $clientAdmin, 'Please follow up today.');

    expect($lead->fresh()->assigned_to)->toBe($rep->id);
    expect(LeadAssignment::where('lead_id', $lead->id)->where('assigned_to', $rep->id)->exists())->toBeTrue();
    expect($rep->fresh()->last_assigned_at)->not->toBeNull();
});

test('a failure partway through assign() rolls back every write already made in that transaction', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $originalLastAssignedAt = $rep->last_assigned_at;

    // A TenantUser that was built but never actually saved: LeadAssignment
    // .assigned_by's foreign key to tenant_users then fails on the second
    // write — AFTER $lead->update() has already run earlier in the SAME
    // transaction — proving the whole operation is atomic, not just its
    // individual steps.
    $neverPersisted = TenantUser::factory()->make(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->id]);

    expect(fn () => app(LeadAssignmentService::class)->assign($lead, $rep, $neverPersisted, 'Please follow up today.'))
        ->toThrow(Exception::class);

    // Nothing persisted: the lead was never actually marked assigned, no
    // LeadAssignment history row exists, and the assignee's last_assigned_at
    // is untouched.
    expect($lead->fresh()->assigned_to)->toBeNull();
    expect($lead->fresh()->assigned_at)->toBeNull();
    expect(LeadAssignment::where('lead_id', $lead->id)->exists())->toBeFalse();
    expect($rep->fresh()->last_assigned_at)->toEqual($originalLastAssignedAt);
});

test('unassigning a lead clears its assignment without touching assignment history', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'assigned_at' => now()]);

    app(LeadAssignmentService::class)->unassign($lead);

    $lead->refresh();
    expect($lead->assigned_to)->toBeNull();
    expect($lead->assigned_at)->toBeNull();
});

test('picking "Unassigned" on the lead detail page actually clears the assignment', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'assigned_at' => now()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('assigneeId', '')
        ->call('assign')
        ->assertHasNoErrors();

    expect($lead->fresh()->assigned_to)->toBeNull();
});

test('picking "Unassigned" on the lead detail page records an activity entry, unlike unassign() called directly', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'assigned_at' => now()]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('assigneeId', '')
        ->call('assign')
        ->assertHasNoErrors();

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->where('action', 'unassigned')->firstOrFail();
    expect($log->changed_by)->toBe($clientAdmin->id);

    $activity = $component->instance()->activity();
    expect($activity[0]['type'])->toBe('unassigned');
});

test('assigning a lead broadcasts a lead update so the assignee\'s board refreshes live', function () {
    Event::fake([LeadUpdated::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('assigneeId', $rep->id)
        ->call('assign');

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('unassigning a lead also broadcasts a lead update', function () {
    Event::fake([LeadUpdated::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'assigned_at' => now()]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('assigneeId', '')
        ->call('assign');

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('a client admin assigning a lead sends the assignee an instant (real-time) notification', function () {
    Event::fake([UserNotified::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('assigneeId', $rep->id)
        ->call('assign');

    $notification = $rep->notifications()->where('type', LeadAssignedNotification::class)->firstOrFail();
    expect($notification->data['assigned_by'])->toBe($clientAdmin->name);

    Event::assertDispatched(UserNotified::class, fn (UserNotified $event): bool => $event->tenantUser->is($rep));
});

test('resubmitting the assignment form with the same assignee does not duplicate history, re-notify, or reset last_assigned_at', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'assigned_at' => now()]);
    $rep->update(['last_assigned_at' => now()->subWeek()]);
    $originalLastAssignedAt = $rep->fresh()->last_assigned_at;

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('assigneeId', $rep->id)
        ->call('assign');

    expect(LeadAssignment::where('lead_id', $lead->id)->count())->toBe(0);
    expect($rep->notifications()->where('type', LeadAssignedNotification::class)->count())->toBe(0);
    expect($rep->fresh()->last_assigned_at)->toEqual($originalLastAssignedAt);
});

test('saving the merged status & assignment form without changing the assignee does not re-notify them', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'assigned_at' => now(), 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('contacted')->id)
        ->set('assigneeId', $rep->id)
        ->call('updateStatusAndAssignment');

    expect($lead->fresh()->status_id)->toBe(leadStatus('contacted')->id);
    expect($rep->notifications()->where('type', LeadAssignedNotification::class)->count())->toBe(0);
});

test('auto-assign does nothing when the tenant uses manual distribution', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(LeadAssignmentService::class)->autoAssign($lead);

    expect($lead->fresh()->assigned_to)->toBeNull();
});

test('auto-assign round-robin picks the eligible user assigned longest ago', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::RoundRobin]);

    $repA = clientUserWithLeadPermission($tenant);
    $repA->update(['last_assigned_at' => now()->subDay()]);
    $repB = clientUserWithLeadPermission($tenant);
    $repB->update(['last_assigned_at' => now()->subMinutes(5)]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(LeadAssignmentService::class)->autoAssign($lead);

    expect($lead->fresh()->assigned_to)->toBe($repA->id);
});

test('auto-assign least-loaded picks the eligible user with fewest open leads', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::LeastLoaded]);

    $busyRep = clientUserWithLeadPermission($tenant);
    Lead::factory()->count(3)->create(['tenant_id' => $tenant->id, 'assigned_to' => $busyRep->id]);

    $freeRep = clientUserWithLeadPermission($tenant);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(LeadAssignmentService::class)->autoAssign($lead);

    expect($lead->fresh()->assigned_to)->toBe($freeRep->id);
});

test('auto-assign does not consider inactive or unqualified users', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::RoundRobin]);

    clientUserWithLeadPermission($tenant)->update(['is_active' => false]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    app(LeadAssignmentService::class)->autoAssign($lead);

    expect($lead->fresh()->assigned_to)->toBeNull();
});

/**
 * M1 fairness-race fix: proves pickRoundRobinUser() runs its candidate
 * query twice per auto-assignment (LeadAssignmentService::
 * selectWithFreshLock()'s lock-and-reselect pattern), not once — the
 * structural signature of the fix, since a single execution is exactly
 * what the pre-fix code already did. True row-level lock BLOCKING can't
 * be observed under SQLite (the test driver) — Laravel's SQLite grammar
 * drops FOR UPDATE entirely, the same documented limitation M8's
 * lockForUpdate() test already established — so the literal `FOR UPDATE`
 * clause is verified separately, as a one-off manual check against the
 * real PostgreSQL connection (see the M1 architecture brief), not here.
 */
test('auto-assign round-robin runs its candidate query twice, matching the lock-and-reselect pattern', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::RoundRobin]);
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(LeadAssignmentService::class)->autoAssign($lead);

    $candidateQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'from "tenant_users"')
            && str_contains($sql, 'order by last_assigned_at IS NOT NULL, "last_assigned_at" asc'));

    DB::disableQueryLog();

    expect($candidateQueries)->toHaveCount(2);
    expect($lead->fresh()->assigned_to)->toBe($rep->id);
});

/**
 * Same proof as above, for pickLeastLoadedUser()'s candidate query —
 * see that test's docblock for why this only proves the query runs
 * twice, not the FOR UPDATE clause itself.
 */
test('auto-assign least-loaded runs its candidate query twice, matching the lock-and-reselect pattern', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::LeastLoaded]);
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(LeadAssignmentService::class)->autoAssign($lead);

    $candidateQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'from "tenant_users"')
            && str_contains($sql, '"assigned_leads_count"'));

    DB::disableQueryLog();

    expect($candidateQueries)->toHaveCount(2);
    expect($lead->fresh()->assigned_to)->toBe($rep->id);
});

/**
 * pickRandomUser() is deliberately never locked (see its own docblock) —
 * proves it still runs exactly once per auto-assignment, unlike the two
 * locked pickers above, and that random distribution's outcome is
 * otherwise unaffected by the M1 fairness-race fix.
 */
test('auto-assign random distribution still runs its candidate query only once and is unaffected by the fairness fix', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $tenant->update(['lead_distribution_mode' => LeadDistributionMode::Automatic, 'auto_distribution_method' => AutoDistributionMethod::Random]);
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(LeadAssignmentService::class)->autoAssign($lead);

    $candidateQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'from "tenant_users"') && str_contains(strtolower($sql), 'random()'));

    DB::disableQueryLog();

    expect($candidateQueries)->toHaveCount(1);
    expect($lead->fresh()->assigned_to)->toBe($rep->id);
});

/**
 * M1 fairness-race fix: LeadAssigned (and therefore
 * SendLeadAssignedNotification, a queued listener) must only fire once
 * the transaction assign() ran inside has actually, durably committed —
 * not merely once assign()'s own internal transaction/savepoint
 * completes. Proven by wrapping assign() in an outer transaction that
 * itself then fails and rolls back: under the previous (pre-M1-fairness)
 * code, assign() fired the event immediately after its own inner
 * transaction released — which, nested inside this test's outer
 * transaction, would happen BEFORE the outer rollback, so the event
 * would already have been dispatched into the fake despite the
 * assignment itself never actually persisting. DB::afterCommit() (the
 * fix) defers the callback until the outermost transaction commits, so
 * when that never happens, the event must never fire either.
 */
test('LeadAssigned is not dispatched if the transaction assign() ran inside is rolled back', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Event::fake([LeadAssigned::class]);

    try {
        DB::transaction(function () use ($lead, $rep, $clientAdmin): void {
            app(LeadAssignmentService::class)->assign($lead, $rep, $clientAdmin);

            throw new RuntimeException('Forcing the outer transaction to roll back after assign() completes.');
        });
    } catch (RuntimeException) {
        // Expected — the point of this test is what happens (or doesn't) as a result.
    }

    Event::assertNotDispatched(LeadAssigned::class);
    expect($lead->fresh()->assigned_to)->toBeNull();
});
