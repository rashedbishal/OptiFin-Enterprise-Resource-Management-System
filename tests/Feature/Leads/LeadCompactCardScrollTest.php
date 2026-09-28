<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use Livewire\Livewire;

test('deals tasks and notes lists are capped and scroll internally', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertSeeHtml('data-test="lead-deals-list" class="flex max-h-96 flex-col gap-3.5 overflow-y-auto overscroll-contain pe-1"')
        ->assertSeeHtml('data-test="lead-tasks-list" class="flex max-h-96 flex-col gap-3.5 overflow-y-auto overscroll-contain pe-1"')
        ->assertSeeHtml('data-test="lead-notes-list" class="flex max-h-96 flex-col gap-3 overflow-y-auto overscroll-contain pe-1"');
});
