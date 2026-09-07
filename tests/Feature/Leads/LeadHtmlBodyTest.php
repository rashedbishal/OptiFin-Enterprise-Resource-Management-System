<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use Livewire\Livewire;

test('a lead with no html body shows only the plain text message, no toggle', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'message' => 'Plain text only.',
        'html_body' => null,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSee('Plain text only.')
        ->assertDontSee('Original Email')
        ->assertDontSeeHtml('<iframe');
});

test('a lead with an html body defaults to the plain text view with a toggle available', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'message' => 'Plain text version.',
        'html_body' => '<p>HTML version</p>',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSet('bodyView', 'text')
        ->assertSee('Plain text version.')
        ->assertSee('Original Email')
        ->assertDontSeeHtml('<iframe');
});

test('switching to the html view renders the original email inside a sandboxed iframe', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'message' => 'Plain text version.',
        'html_body' => '<p>HTML version</p>',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('bodyView', 'html')
        ->assertSeeHtml('<iframe')
        ->assertSeeHtml('sandbox')
        ->assertSeeHtml('&lt;p&gt;HTML version&lt;/p&gt;')
        ->assertDontSee('Plain text version.');
});
