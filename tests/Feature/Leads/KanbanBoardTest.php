<?php

use App\Enums\LeadStatus;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use Livewire\Livewire;

test('the board groups leads by status', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::New]);
    Lead::factory()->count(2)->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::Won]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);

    $board = $component->get('boardLeads');

    expect($board->get(LeadStatus::New->value))->toHaveCount(1);
    expect($board->get(LeadStatus::Won->value))->toHaveCount(2);
    expect($board->get(LeadStatus::Lost->value, collect()))->toHaveCount(0);
});

test('a client admin can drag a lead to a new status', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::New]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('moveLead', $lead->id, LeadStatus::Contacted->value)
        ->assertDispatched('toast-show');

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatus::Contacted);
    expect($lead->first_contacted_at)->not->toBeNull();
    expect(LeadStatusLog::where('lead_id', $lead->id)->where('new_status', LeadStatus::Contacted)->exists())->toBeTrue();
});

test('moving a lead to won sets the closed-at timestamp', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::Negotiation]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('moveLead', $lead->id, LeadStatus::Won->value);

    expect($lead->fresh()->closed_at)->not->toBeNull();
});

test('dropping a lead on the column it is already in does not log a status change', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::Contacted]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('moveLead', $lead->id, LeadStatus::Contacted->value);

    expect(LeadStatusLog::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('an invalid status value is ignored', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::New]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('moveLead', $lead->id, 'not-a-real-status');

    expect($lead->fresh()->status)->toBe(LeadStatus::New);
});

test('a client user without update permission cannot move a lead they are assigned', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::New, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('moveLead', $lead->id, LeadStatus::Contacted->value)
        ->assertDispatched('toast-show');

    expect($lead->fresh()->status)->toBe(LeadStatus::New);
});

test('a client admin cannot move another tenant\'s lead', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id, 'status' => LeadStatus::New]);

    Livewire::actingAs($clientAdminA)
        ->test(Index::class)
        ->call('moveLead', $leadB->id, LeadStatus::Contacted->value);

    expect($leadB->fresh()->status)->toBe(LeadStatus::New);
});

test('a client admin can open the lead detail panel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('viewLead', $lead->id);

    expect($component->get('selectedLeadId'))->toBe($lead->id);
    expect($component->get('showLeadPanel'))->toBeTrue();
    expect($component->get('selectedLead')->id)->toBe($lead->id);
});

test('a client user cannot open a lead panel for a lead assigned to someone else', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $otherLead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('viewLead', $otherLead->id)
        ->assertDispatched('toast-show');

    expect($component->get('showLeadPanel'))->toBeFalse();
    expect($component->get('selectedLeadId'))->toBeNull();
});

test('closing the lead panel drops the selected lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('viewLead', $lead->id)
        ->set('showLeadPanel', false)
        ->assertSet('selectedLeadId', null);
});
