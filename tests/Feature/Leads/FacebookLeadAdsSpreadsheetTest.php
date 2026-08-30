<?php

use App\Enums\LeadSourceType;
use App\Exports\FacebookLeadAdsExport;
use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\SourceLeads;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;

test('the leads index shows one aggregate row per facebook lead ads source instead of one row per submission', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Fb Lead Ads']);
    Lead::factory()->count(3)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $websiteSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $websiteSource->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);

    expect($component->get('facebookLeadAdsSources'))->toHaveCount(1);
    expect($component->get('facebookLeadAdsSources')->first()->leads_count)->toBe(3);
    expect($component->get('leads'))->toHaveCount(1);
});

test('a second facebook lead ads source gets its own aggregate row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $sourceA = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Fb Lead Ads']);
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Fb Lead Ads (Campaign 2)']);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $sourceA->id]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $sourceB->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertSee('Fb Lead Ads')
        ->assertSee('Fb Lead Ads (Campaign 2)');
});

test('a non-admin agent only sees a facebook lead ads aggregate row for sources with a lead assigned to them', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $component = Livewire::actingAs($rep)->test(Index::class);

    expect($component->get('facebookLeadAdsSources'))->toHaveCount(0);

    $assignedLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $rep->id]);

    $component = Livewire::actingAs($rep)->test(Index::class);

    expect($component->get('facebookLeadAdsSources'))->toHaveCount(1);
    expect($component->get('facebookLeadAdsSources')->first()->leads_count)->toBe(1);
});

test('the spreadsheet view lists a facebook lead ads source\'s submissions with dynamic columns from field_data', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'name' => 'Jane Doe',
        'source_data' => [
            'leadgen_id' => 'lg-1',
            'form_name' => 'Contact Us',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Jane Doe']],
                ['name' => 'city', 'values' => ['Dhaka']],
            ],
        ],
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(SourceLeads::class, ['leadSource' => $source])
        ->assertSee('Full Name')
        ->assertSee('City')
        ->assertSee('Jane Doe')
        ->assertSee('Dhaka')
        ->assertSee('Contact Us');

    expect($lead)->not->toBeNull();
});

test('the spreadsheet view unions field_data columns across leads from different forms', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'source_data' => ['field_data' => [['name' => 'full_name', 'values' => ['A']]]],
    ]);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'source_data' => ['field_data' => [['name' => 'budget', 'values' => ['$500']]]],
    ]);

    $component = Livewire::actingAs($clientAdmin)->test(SourceLeads::class, ['leadSource' => $source]);

    expect($component->get('fieldColumns'))->toBe(['full_name', 'budget']);
});

test('a non-admin agent only sees leads assigned to them in the spreadsheet view, and the field columns are scoped to those', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    $ownLead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'assigned_to' => $rep->id,
        'name' => 'Mine',
        'source_data' => ['field_data' => [['name' => 'my_field', 'values' => ['visible']]]],
    ]);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'name' => 'NotMine',
        'source_data' => ['field_data' => [['name' => 'other_field', 'values' => ['hidden']]]],
    ]);

    $component = Livewire::actingAs($rep)
        ->test(SourceLeads::class, ['leadSource' => $source])
        ->assertSee('Mine')
        ->assertDontSee('NotMine');

    expect($component->get('fieldColumns'))->toBe(['my_field']);
});

test('visiting the spreadsheet view for a non-facebook-lead-ads source is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);

    Livewire::actingAs($clientAdmin)
        ->test(SourceLeads::class, ['leadSource' => $source])
        ->assertStatus(404);
});

test('a client user without lead view permission cannot open the spreadsheet view', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $noPermissionUser = clientUserWithLeadPermission($tenant, []);

    $this->actingAs($noPermissionUser)
        ->get(route('tenant-leads.source', $source))
        ->assertForbidden();
});

test('the leads index source type filter no longer offers facebook lead ads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);

    expect($component->instance()->sourceTypeOptions())->not->toContain(LeadSourceType::FacebookLeadAds->value);
});

test('the leads index shows a view all button (not delete) for a facebook lead ads source with leads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertSee('View All')
        ->assertDontSee('Delete');
});

test('the leads index shows a delete button (not view all) for an empty facebook lead ads source', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertSee('Delete')
        ->assertDontSee('View All');
});

test('a client admin can delete an empty facebook lead ads source', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('deleteSource', $source->id)
        ->assertDispatched('toast-show');

    expect(LeadSource::find($source->id))->toBeNull();
});

test('deleting a facebook lead ads source that has leads is refused', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('deleteSource', $source->id);

    expect(LeadSource::find($source->id))->not->toBeNull();
});

test('a client user without delete permission cannot delete a facebook lead ads source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('deleteSource', $source->id)
        ->assertForbidden();

    expect(LeadSource::find($source->id))->not->toBeNull();
});

test('a client admin cannot delete another tenant\'s facebook lead ads source', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenantB->id, 'type' => LeadSourceType::FacebookLeadAds]);

    expect(fn () => Livewire::actingAs($clientAdminA)->test(Index::class)->call('deleteSource', $sourceB->id))
        ->toThrow(ModelNotFoundException::class);

    expect(LeadSource::find($sourceB->id))->not->toBeNull();
});

test('the spreadsheet view downloads an xlsx file named after the source and the current timestamp', function () {
    $this->travelTo(Carbon::create(2026, 8, 30, 14, 32, 10));

    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Fb Lead Ads']);
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'source_data' => ['field_data' => [['name' => 'full_name', 'values' => ['Jane Doe']]]],
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(SourceLeads::class, ['leadSource' => $source])
        ->call('downloadXlsx')
        ->assertFileDownloaded('fb-lead-ads-2026-08-30_143210.xlsx');
});

test('the facebook lead ads export maps headings and rows from field_data', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'name' => 'Jane Doe',
        'source_data' => [
            'form_name' => 'Contact Us',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Jane Doe']],
                ['name' => 'city', 'values' => ['Dhaka']],
            ],
        ],
    ]);

    $export = new FacebookLeadAdsExport(new Collection([$lead]), ['full_name', 'city']);

    expect($export->headings())->toBe(['Name', 'Full Name', 'City', 'Form', 'Status', 'Submitted At']);

    $row = $export->map($lead);

    expect($row[0])->toBe('Jane Doe');
    expect($row[1])->toBe('Jane Doe');
    expect($row[2])->toBe('Dhaka');
    expect($row[3])->toBe('Contact Us');
});
