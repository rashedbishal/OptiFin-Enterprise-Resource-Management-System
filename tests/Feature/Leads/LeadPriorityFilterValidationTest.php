<?php

use App\Enums\LeadPriority;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use Livewire\Livewire;

/*
 * Batch 5 — confirmed crash-path fix (Finding #2). FiltersLeadsQuery::
 * applyDropdownFilters() called LeadPriority::from($this->priority)
 * directly on a plain wire:model.live-bound string (the "priority" filter
 * dropdown on both the Leads table and Kanban board), so a tampered
 * Livewire request carrying a non-enum value threw an uncaught ValueError
 * (HTTP 500) — reachable by any authenticated tenant user, not just an
 * admin.
 */

test('an invalid priority filter value does not throw and does not filter out any leads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $low = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Low]);
    $urgent = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Urgent]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('priority', 'not-a-real-priority');

    $ids = $component->get('leads')->pluck('id');

    expect($ids)->toContain($low->id)->toContain($urgent->id);
});

test('a valid priority filter still filters exactly as before', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $low = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Low]);
    $urgent = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Urgent]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('priority', LeadPriority::Urgent->value);

    $ids = $component->get('leads')->pluck('id');

    expect($ids)->toContain($urgent->id)->not->toContain($low->id);
});

test('clearing the priority filter back to empty still shows every lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $low = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Low]);
    $urgent = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Urgent]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('priority', '');

    $ids = $component->get('leads')->pluck('id');

    expect($ids)->toContain($low->id)->toContain($urgent->id);
});
