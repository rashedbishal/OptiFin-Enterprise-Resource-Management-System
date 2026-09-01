<?php

use App\Enums\LeadStatus;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use Livewire\Livewire;

test('a client admin sees the classic filterable table, not the Kanban board', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::New]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertSeeHtml('data-flux-table')
        ->assertDontSeeHtml('draggable="true"');
});

test('a client user (sales rep) sees the Kanban board, not the table', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status' => LeadStatus::New, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertDontSeeHtml('data-flux-table')
        ->assertSeeHtml('draggable="true"');
});
