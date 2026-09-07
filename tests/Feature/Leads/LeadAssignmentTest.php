<?php

use App\Enums\AutoDistributionMethod;
use App\Enums\LeadDistributionMode;
use App\Events\LeadUpdated;
use App\Events\UserNotified;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadDetailUpdateLog;
use App\Notifications\LeadAssignedNotification;
use App\Services\LeadAssignmentService;
use Illuminate\Support\Facades\Event;
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
