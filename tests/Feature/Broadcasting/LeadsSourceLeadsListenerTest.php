<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\SourceLeads;
use App\Models\LeadSource;
use Livewire\Livewire;

test('the leads source page listens for lead.received on its own tenant channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    $listeners = Livewire::actingAs($clientAdmin)
        ->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')
        ->instance()
        ->getListeners();

    expect($listeners)->toHaveKey('echo-private:tenant.'.$tenant->id.',.lead.received', '$refresh');
});

test('the leads source page listens for lead.updated on its own tenant channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    $listeners = Livewire::actingAs($clientAdmin)
        ->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')
        ->instance()
        ->getListeners();

    expect($listeners)->toHaveKey('echo-private:tenant.'.$tenant->id.',.lead.updated', '$refresh');
});
