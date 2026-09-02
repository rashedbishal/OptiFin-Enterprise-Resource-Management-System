<?php

use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('the board groups leads by status', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);
    Lead::factory()->count(2)->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('won')->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);

    $board = $component->get('boardLeads');

    expect($board->get(leadStatus('new')->id))->toHaveCount(1);
    expect($board->get(leadStatus('won')->id))->toHaveCount(2);
    expect($board->get(leadStatus('lost')->id, collect()))->toHaveCount(0);
});

test('a client admin can drag a lead to a new status', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('moveLead', $lead->id, leadStatus('contacted')->id)
        ->assertDispatched('toast-show');

    $lead->refresh();
    expect($lead->status_id)->toBe(leadStatus('contacted')->id);
    expect($lead->first_contacted_at)->not->toBeNull();
    expect(LeadStatusLog::where('lead_id', $lead->id)->where('new_status_id', leadStatus('contacted')->id)->exists())->toBeTrue();
});

test('dragging a lead to a new status broadcasts a lead update so other viewers refresh live', function () {
    Event::fake([LeadUpdated::class]);
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('moveLead', $lead->id, leadStatus('contacted')->id);

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event): bool => $event->lead->is($lead));
});

test('moving a lead to won sets the closed-at timestamp', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('negotiation')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('moveLead', $lead->id, leadStatus('won')->id);

    expect($lead->fresh()->closed_at)->not->toBeNull();
});

test('dropping a lead on the column it is already in does not log a status change', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('contacted')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('moveLead', $lead->id, leadStatus('contacted')->id);

    expect(LeadStatusLog::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('an invalid status value is ignored', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('moveLead', $lead->id, 'not-a-real-status');

    expect($lead->fresh()->status_id)->toBe(leadStatus('new')->id);
});

test('a client user without update permission cannot move a lead they are assigned', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('moveLead', $lead->id, leadStatus('contacted')->id)
        ->assertDispatched('toast-show');

    expect($lead->fresh()->status_id)->toBe(leadStatus('new')->id);
});

test('a client admin cannot move another tenant\'s lead', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdminA)
        ->test(Index::class)
        ->call('moveLead', $leadB->id, leadStatus('contacted')->id);

    expect($leadB->fresh()->status_id)->toBe(leadStatus('new')->id);
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
