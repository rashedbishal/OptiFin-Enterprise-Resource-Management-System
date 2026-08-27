<?php

use App\Enums\LeadStatus;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use Livewire\Livewire;

test('changing a lead status from new records the first-contacted timestamp and a status log', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::New]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('status', LeadStatus::Contacted->value)
        ->call('updateStatus');

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatus::Contacted);
    expect($lead->first_contacted_at)->not->toBeNull();
    expect(LeadStatusLog::where('lead_id', $lead->id)->where('new_status', LeadStatus::Contacted)->exists())->toBeTrue();
});

test('moving a lead to won sets the closed-at timestamp', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::Negotiation]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->set('status', LeadStatus::Won->value)
        ->call('updateStatus');

    expect($lead->fresh()->closed_at)->not->toBeNull();
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
