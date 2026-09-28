<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadNote;
use Livewire\Livewire;

test('compact cards share a consistent height and their lists scroll internally', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadNote::factory()->create(['lead_id' => $lead->id, 'tenant_user_id' => $clientAdmin->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])
        ->call('completeInitialLoad')
        ->assertSeeHtml('grid-cols-[repeat(auto-fit,minmax(min(100%,20rem),1fr))] items-stretch gap-6')
        ->assertSeeHtml('grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))] items-start gap-6')
        ->assertSeeHtml('data-test="lead-deals-list" class="min-h-0 flex flex-1 flex-col gap-3.5 overflow-y-auto overscroll-contain pe-1"')
        ->assertSeeHtml('data-test="lead-tasks-list" class="min-h-0 flex flex-1 flex-col gap-3.5 overflow-y-auto overscroll-contain pe-1"')
        ->assertSeeHtml('data-test="lead-notes-list" class="min-h-0 flex flex-1 flex-col gap-3 overflow-y-auto overscroll-contain pe-1"')
        ->assertSeeHtml('min-h-32 overflow-hidden rounded-xl');
});

test('the lead flyout uses the same consistent compact-card layout', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])
        ->call('completeInitialLoad')
        ->assertSeeHtml('grid grid-cols-[repeat(auto-fit,minmax(min(100%,20rem),1fr))] items-stretch gap-5')
        ->assertSeeHtml('grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))] items-start gap-5');
});
