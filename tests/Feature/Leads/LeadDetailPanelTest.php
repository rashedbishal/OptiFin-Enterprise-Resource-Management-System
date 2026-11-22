<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use Livewire\Livewire;

test('the panel view renders the same core sections as the full lead page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Panel Test Lead']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->assertSee('Panel Test Lead')
        ->assertSee('Details')
        ->assertSee('Activity')
        ->assertSee('Add Note')
        ->assertSee('Status')
        ->assertSee('Assignment')
        ->assertSee('Contact')
        ->assertSee('Company')
        ->assertSee('Deal')
        ->assertDontSee('Breadcrumb', escape: false);
});

test('the full page shows Company/Deal read-only and no Contact form, editing only from the panel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'Apex Global Logistics Ltd.']);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSee('Apex Global Logistics Ltd.')
        ->assertDontSeeHtml('wire:submit="updateContact"')
        ->assertDontSeeHtml('wire:submit="updateCompany"')
        ->assertDontSeeHtml('wire:submit="updateDeal"');
});

test('updating status from the panel notifies the board instead of redirecting', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->set('status', leadStatus('contacted')->id)
        ->call('updateStatus')
        ->assertDispatched('lead-panel-updated');

    expect($lead->fresh()->status_id)->toBe(leadStatus('contacted')->id);
});

test('deleting from the panel dispatches an event instead of redirecting', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('unqualified')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->set('deletePassword', 'password')->call('delete')
        ->assertDispatched('lead-panel-deleted', leadId: $lead->id);

    expect(Lead::find($lead->id))->toBeNull();
});

test('the full page still redirects to the index on delete', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('unqualified')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('deletePassword', 'password')->call('delete')
        ->assertRedirect(route('tenant-leads.index'));
});
