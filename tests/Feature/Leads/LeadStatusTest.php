<?php

use App\Enums\LeadPriority;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('changing a lead status from new records the first-contacted timestamp and a status log', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('status', leadStatus('contacted')->id)
        ->call('updateStatus');

    $lead->refresh();
    expect($lead->status_id)->toBe(leadStatus('contacted')->id);
    expect($lead->first_contacted_at)->not->toBeNull();
    expect(LeadStatusLog::where('lead_id', $lead->id)->where('new_status_id', leadStatus('contacted')->id)->exists())->toBeTrue();
});

test('moving a lead to won sets the closed-at timestamp', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('negotiation')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('status', leadStatus('won')->id)
        ->call('updateStatus');

    expect($lead->fresh()->closed_at)->not->toBeNull();
});

test('changing a lead status broadcasts a lead update so other viewers refresh live', function () {
    Event::fake([LeadUpdated::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('status', leadStatus('contacted')->id)
        ->call('updateStatus');

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('a lead detail view picks up a status changed elsewhere when its live-update listener fires', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'priority' => LeadPriority::Low]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead]);

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
        ->test(Show::class, ['lead' => $lead])
        ->set('newNote', 'Called, left a voicemail.')
        ->call('addNote');

    expect($lead->leadNotes()->where('note', 'Called, left a voicemail.')->exists())->toBeTrue();
});
