<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadSource;
use Livewire\Livewire;

test('a client admin sees the classic filterable table, not the Kanban board', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertSeeHtml('data-flux-table')
        ->assertDontSeeHtml('draggable="true"');
});

test('the mobile card stack has no View/Delete buttons — the whole card links to the lead instead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    $html = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->html();

    expect($html)->toContain('wire:key="lead-card-'.$lead->id.'"');
    expect($html)->toContain('href="'.route('tenant-leads.show', $lead).'"');

    // The desktop table (a separate block in the same response) still has
    // its own Delete button for this lead — one occurrence of the actual
    // click handler (Flux also renders the same call as wire:target, which
    // is why the raw string appears twice per button) means the mobile
    // card didn't render a second copy of it.
    expect(substr_count($html, 'wire:click="confirmDeleteLead(\''.$lead->id.'\')"'))->toBe(1);
});

test('a client user (sales rep) sees the Kanban board, not the table', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertDontSeeHtml('data-flux-table')
        ->assertSeeHtml('draggable="true"');
});

test('the source filter only offers types the tenant actually has a lead source for, plus manual entry always', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);

    $options = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->instance()->sourceTypeOptions();

    expect($options)->toContain(LeadSourceType::Whatsapp->value);
    expect($options)->toContain(LeadSourceType::Manual->value);
    expect($options)->not->toContain(LeadSourceType::Instagram->value);
    expect($options)->not->toContain(LeadSourceType::Email->value);
    expect($options)->not->toContain(LeadSourceType::LinkedInLeadGen->value);
});

test('a client user\'s source filter offers facebook lead ads when the tenant has one configured, but the client admin\'s does not', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    $repOptions = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad')->instance()->sourceTypeOptions();
    expect($repOptions)->toContain(LeadSourceType::FacebookLeadAds->value);

    $adminOptions = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->instance()->sourceTypeOptions();
    expect($adminOptions)->not->toContain(LeadSourceType::FacebookLeadAds->value);
});
