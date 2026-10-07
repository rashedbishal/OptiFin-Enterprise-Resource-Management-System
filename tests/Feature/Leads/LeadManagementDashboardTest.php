<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\LeadManagement\Dashboard;
use App\Models\LeadSource;
use Livewire\Livewire;

/*
 * The "Calling Settings" tile only makes sense once the tenant has a
 * WhatsApp lead source — without one, WhatsApp calling (and so a custom
 * ringtone for it) can never happen, so the tile must be omitted entirely
 * rather than shown locked.
 */

test('the Calling Settings tile is hidden when the tenant has no WhatsApp lead source', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Dashboard::class)
        ->assertDontSee('Calling Settings');
});

test('the Calling Settings tile appears once the tenant has a WhatsApp lead source', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    LeadSource::factory()->for($tenant)->create(['type' => LeadSourceType::Whatsapp]);

    Livewire::actingAs($clientAdmin)
        ->test(Dashboard::class)
        ->assertSee('Calling Settings');
});

test('a WhatsApp lead source belonging to another tenant does not surface the tile', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();

    LeadSource::factory()->for($otherTenant)->create(['type' => LeadSourceType::Whatsapp]);

    Livewire::actingAs($clientAdmin)
        ->test(Dashboard::class)
        ->assertDontSee('Calling Settings');
});
