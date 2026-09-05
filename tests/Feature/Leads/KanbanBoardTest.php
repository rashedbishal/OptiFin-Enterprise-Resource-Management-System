<?php

use App\Enums\LeadPriority;
use App\Enums\LeadSourceType;
use App\Events\LeadUpdated;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadSource;
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

test('a client user\'s board shows their assigned facebook lead ads leads as normal cards, unlike the client admin table', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $fbLead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'assigned_to' => $rep->id,
        'status_id' => leadStatus('new')->id,
    ]);

    $repBoard = Livewire::actingAs($rep)->test(Index::class)->get('boardLeads');
    expect($repBoard->get(leadStatus('new')->id, collect())->pluck('id'))->toContain($fbLead->id);

    $adminBoard = Livewire::actingAs($clientAdmin)->test(Index::class)->get('boardLeads');
    expect($adminBoard->get(leadStatus('new')->id, collect())->pluck('id'))->not->toContain($fbLead->id);
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

test('a ?lead= query param opens that lead\'s panel on load, same as clicking its card', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class, ['openLeadId' => $lead->id]);

    expect($component->get('selectedLeadId'))->toBe($lead->id);
    expect($component->get('showLeadPanel'))->toBeTrue();
});

test('the real ?lead= URL a notification link points to actually opens the panel, end to end', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    $this->actingAs($rep)
        ->get(route('tenant-leads.index', ['lead' => $lead->id]))
        ->assertOk()
        ->assertSeeLivewire(Index::class);
});

test('a ?lead= query param for a lead the viewer cannot see is denied the same way viewLead() already is', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $otherLead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($rep)->test(Index::class, ['openLeadId' => $otherLead->id]);

    expect($component->get('showLeadPanel'))->toBeFalse();
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

test('clicking a different card updates the ?lead= query param to match, not the one from a notification link', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $leadA = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenant->id]);

    // Simulates arriving via a notification link for lead A, then clicking
    // a different card (lead B) on the board without ever refreshing.
    Livewire::actingAs($clientAdmin)
        ->test(Index::class, ['openLeadId' => $leadA->id])
        ->assertSet('openLeadId', $leadA->id)
        ->call('viewLead', $leadB->id)
        ->assertSet('selectedLeadId', $leadB->id)
        ->assertSet('openLeadId', $leadB->id);
});

test('closing the panel also clears the ?lead= query param, not just the selected lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('viewLead', $lead->id)
        ->assertSet('openLeadId', $lead->id)
        ->set('showLeadPanel', false)
        ->assertSet('openLeadId', null);
});

test('deleting the open lead from the panel also clears the ?lead= query param', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('viewLead', $lead->id)
        ->call('delete', $lead->id)
        ->assertSet('openLeadId', null);
});

test('kanban board filters leads by priority and source type', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $whatsappSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $emailSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);

    $lead1 = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_to' => $rep->id,
        'source_id' => $whatsappSource->id,
        'priority' => LeadPriority::High,
        'status_id' => leadStatus('new')->id,
    ]);

    $lead2 = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_to' => $rep->id,
        'source_id' => $emailSource->id,
        'priority' => LeadPriority::Low,
        'status_id' => leadStatus('new')->id,
    ]);

    $component = Livewire::actingAs($rep)
        ->test(Index::class)
        ->set('priority', 'high');

    $board = $component->get('boardLeads');
    $newLeads = $board->get(leadStatus('new')->id, collect());
    expect($newLeads->pluck('id'))->toContain($lead1->id)->not->toContain($lead2->id);

    $component->set('priority', '')
        ->set('sourceType', 'whatsapp');

    $board = $component->get('boardLeads');
    $newLeads = $board->get(leadStatus('new')->id, collect());
    expect($newLeads->pluck('id'))->toContain($lead1->id)->not->toContain($lead2->id);
});

test('the board filters leads by a created-at date range, applied instantly with no search button', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);

    $oldLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'status_id' => leadStatus('new')->id]);
    $oldLead->forceFill(['created_at' => now()->subDays(10)])->save();

    $recentLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'status_id' => leadStatus('new')->id]);
    $recentLead->forceFill(['created_at' => now()->subDay()])->save();

    $component = Livewire::actingAs($rep)
        ->test(Index::class)
        ->set('fromDate', now()->subDays(3)->toDateString());

    $board = $component->get('boardLeads');
    $newLeads = $board->get(leadStatus('new')->id, collect());
    expect($newLeads->pluck('id'))->toContain($recentLead->id)->not->toContain($oldLead->id);

    $component->set('fromDate', '')
        ->set('toDate', now()->subDays(5)->toDateString());

    $board = $component->get('boardLeads');
    $newLeads = $board->get(leadStatus('new')->id, collect());
    expect($newLeads->pluck('id'))->toContain($oldLead->id)->not->toContain($recentLead->id);
});
