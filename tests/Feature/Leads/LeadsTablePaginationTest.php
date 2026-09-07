<?php

use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use Livewire\Livewire;

test('the leads table defaults to 15 per page before the viewport is known', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->count(20)->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad');

    expect($component->get('perPage'))->toBe(15);
    expect($component->instance()->leads()->count())->toBe(15);
});

test('setPerPage(10) switches the table to the mobile page size', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->count(20)->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->call('setPerPage', 10);

    expect($component->get('perPage'))->toBe(10);
    expect($component->instance()->leads()->count())->toBe(10);
});

test('switching page size resets back to the first page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->count(20)->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->set('paginators.page', 2)
        ->call('setPerPage', 10);

    expect($component->instance()->leads()->currentPage())->toBe(1);
});

test('setPerPage ignores anything other than the two real page sizes', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->count(20)->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')
        ->call('setPerPage', 500);

    expect($component->get('perPage'))->toBe(15);
});

test('the view initializes the page size from the viewport via Alpine', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertSeeHtml('$wire.setPerPage(mobile.matches ? 10 : 15)');
});
