<?php

use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use Livewire\Livewire;

test('a sales rep without update permission cannot change a lead status and gets a friendly message', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->set('status', leadStatus('contacted')->id)
        ->call('updateStatus')
        ->assertOk()
        ->assertDispatched('toast-show');

    expect($lead->fresh()->status_id)->toBe(leadStatus('new')->id);
});

test('the status/note forms are hidden from a viewer without update permission', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->assertDontSee('Add Note')
        ->assertDontSeeHtml('wire:submit="updateStatus"');
});

test('the status/note forms are visible to a viewer with update permission', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->assertSee('Add Note')
        ->assertSeeHtml('wire:submit="updateStatus"');
});

test('the delete button on the board is hidden from a viewer without delete permission', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])
        ->assertDontSeeHtml('wire:click="delete"');
});

test('a lead assigned to someone else cannot be viewed and shows the custom 403 page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $otherLead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($rep)
        ->get(route('tenant-leads.show', $otherLead))
        ->assertForbidden()
        ->assertSee('Access Denied');
});

test('the Kanban board "…" move menu is hidden without update permission but shown with it', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $viewer = clientUserWithLeadPermission($tenant, ['view']);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $viewer->id]);

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->assertDontSeeHtml('aria-label="Move to…"');

    $mover = clientUserWithLeadPermission($tenant, ['view', 'update']);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $mover->id]);

    Livewire::actingAs($mover)
        ->test(Index::class)
        ->assertSeeHtml('aria-label="Move to…"');
});
