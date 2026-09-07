<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use Livewire\Livewire;

test('the lead show page listens for lead.updated on its own lead channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $listeners = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->instance()
        ->getListeners();

    expect($listeners)->toHaveKey('echo-private:lead.'.$lead->id.',.lead.updated', 'refreshLead');
});

test('refreshLead reloads the lead so an appended follow-up message is visible', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'message' => 'Original message']);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    $lead->update(['message' => 'Original message'.PHP_EOL.'Follow-up message']);

    $component->call('refreshLead')
        ->assertSee('Follow-up message');
});
