<?php

use App\Livewire\Tenant\Leads\Index;
use Livewire\Livewire;

test('the leads index listens for lead.received on its own tenant channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $listeners = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->instance()
        ->getListeners();

    expect($listeners)->toHaveKey('echo-private:tenant.'.$tenant->id.',.lead.received', '$refresh');
});

test('the leads index listens for lead.updated on its own tenant channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $listeners = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->instance()
        ->getListeners();

    expect($listeners)->toHaveKey('echo-private:tenant.'.$tenant->id.',.lead.updated', '$refresh');
});
