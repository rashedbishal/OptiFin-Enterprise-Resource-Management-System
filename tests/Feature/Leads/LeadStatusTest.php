<?php

use App\Enums\LeadPriority;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadScoreLog;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('updating status to an id outside this tenant\'s own pipeline is silently rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);
    $foreignStatus = LeadStatus::factory()->create();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', $foreignStatus->id)
        ->call('updateStatus');

    expect($lead->fresh()->status_id)->toBe(leadStatus('new')->id);
    expect(LeadStatusLog::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('an invalid priority value is rejected by validation instead of crashing', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('contacted')->id)
        ->set('priority', 'not-a-real-priority')
        ->call('updateStatus')
        ->assertHasErrors(['priority']);

    expect($lead->fresh()->status_id)->toBe(leadStatus('new')->id);
});

test('changing a lead status from new records the first-contacted timestamp and a status log', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('contacted')->id)
        ->call('updateStatus');

    $lead->refresh();
    expect($lead->status_id)->toBe(leadStatus('contacted')->id);
    expect($lead->first_contacted_at)->not->toBeNull();
    expect(LeadStatusLog::where('lead_id', $lead->id)->where('new_status_id', leadStatus('contacted')->id)->exists())->toBeTrue();
});

test('changing only priority (status unchanged) actually saves it and records an activity entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'priority' => LeadPriority::Low]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('new')->id)
        ->set('priority', LeadPriority::High->value)
        ->call('updateStatus')
        ->assertHasNoErrors();

    expect($lead->fresh()->priority)->toBe(LeadPriority::High);
    expect(LeadStatusLog::where('lead_id', $lead->id)->exists())->toBeFalse();

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->firstOrFail();
    expect($log->changed_fields)->toBe(['priority']);

    $activity = $component->instance()->activity();
    expect($activity[0]['type'])->toBe('details_updated');
    expect($activity[0]['fields'])->toBe(['Priority']);
});

test('changing status and priority together records both a status log and a priority activity entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'priority' => LeadPriority::Low]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('contacted')->id)
        ->set('priority', LeadPriority::High->value)
        ->call('updateStatus');

    $lead->refresh();
    expect($lead->status_id)->toBe(leadStatus('contacted')->id);
    expect($lead->priority)->toBe(LeadPriority::High);

    expect(LeadStatusLog::where('lead_id', $lead->id)->exists())->toBeTrue();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->where('changed_fields', json_encode(['priority']))->exists())->toBeTrue();
});

test('a failure partway through updateStatus() rolls back the status change, the priority change, and every audit/scoring side effect', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'priority' => LeadPriority::Low]);

    // A TenantUser with the identical tenant_id/role_id/user_type as the
    // real, already-authorized $clientAdmin, but never actually persisted:
    // Gate::denies('update', $lead) passes (it only reads in-memory
    // tenant_id/user_type/role attributes, never re-queries the acting
    // user's own row), but LeadStatusLog.changed_by's foreign key to
    // tenant_users then fails on the write immediately after
    // $this->lead->save() — the same never-persisted-FK-reference technique
    // already established in LeadMergeTest.php and LeadAssignmentTest.php,
    // applied via the authenticated user instead of an explicit parameter
    // since updateStatus() sources changed_by from Auth::id().
    $neverPersisted = TenantUser::factory()->make([
        'id' => (string) Str::ulid(),
        'tenant_id' => $clientAdmin->tenant_id,
        'role_id' => $clientAdmin->role_id,
        'user_type' => $clientAdmin->user_type,
    ]);

    expect(fn () => Livewire::actingAs($neverPersisted)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('contacted')->id)
        ->set('priority', LeadPriority::High->value)
        ->call('updateStatus'))
        ->toThrow(Exception::class);

    // Nothing persisted: the status and priority changes made by the
    // earlier $this->lead->save() call inside the same transaction were
    // rolled back, no LeadStatusLog/LeadDetailUpdateLog row exists, and no
    // scoring recompute (which runs later in the same transaction, after
    // the audit-log write that failed) ever left a trace.
    $lead->refresh();
    expect($lead->status_id)->toBe(leadStatus('new')->id);
    expect($lead->priority)->toBe(LeadPriority::Low);
    expect($lead->first_contacted_at)->toBeNull();
    expect(LeadStatusLog::where('lead_id', $lead->id)->exists())->toBeFalse();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->exists())->toBeFalse();
    expect(LeadScoreLog::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('resubmitting the status form with no actual change to status or priority is a no-op', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'priority' => LeadPriority::Low]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('new')->id)
        ->set('priority', LeadPriority::Low->value)
        ->call('updateStatus');

    expect(LeadStatusLog::where('lead_id', $lead->id)->exists())->toBeFalse();
    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('moving a lead to won sets the closed-at timestamp', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('negotiation')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('won')->id)
        ->call('updateStatus');

    expect($lead->fresh()->closed_at)->not->toBeNull();
});

test('changing a lead status broadcasts a lead update so other viewers refresh live', function () {
    Event::fake([LeadUpdated::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('contacted')->id)
        ->call('updateStatus');

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('a lead detail view picks up a status changed elsewhere when its live-update listener fires', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'priority' => LeadPriority::Low]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    // Simulate another user changing the status directly, then the
    // `.lead.updated` broadcast listener firing on this viewer's page.
    $lead->update(['status_id' => leadStatus('won')->id, 'priority' => LeadPriority::High]);
    $component->call('refreshLead');

    $component->assertSet('status', leadStatus('won')->id);
    $component->assertSet('priority', LeadPriority::High->value);
});

test('adding a note to a lead persists it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('newNote', 'Called, left a voicemail.')
        ->call('addNote');

    expect($lead->leadNotes()->where('note', 'Called, left a voicemail.')->exists())->toBeTrue();
});
