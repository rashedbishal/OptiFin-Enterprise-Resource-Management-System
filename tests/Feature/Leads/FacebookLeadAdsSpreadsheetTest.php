<?php

use App\Enums\LeadSourceType;
use App\Exports\FacebookLeadAdsExport;
use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Livewire\Tenant\Leads\SourceLeads;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Actually renders the export to a real XLSX file and reads the given
 * cell back — proving the ACTUAL written cell data type, not just what
 * map() returns. map() alone can't prove this: it returns the raw string
 * unchanged either way, since the formula-injection protection happens at
 * the cell-binding layer (FacebookLeadAdsExport::bindValue()), not in
 * map()'s own return value.
 *
 * @return array{type: string, value: mixed}
 */
function exportedCell(FacebookLeadAdsExport $export, string $coordinate): array
{
    $binary = Excel::raw($export, ExcelWriterType::XLSX);

    $tmpPath = tempnam(sys_get_temp_dir(), 'fb_lead_ads_export_test_');
    file_put_contents($tmpPath, $binary);

    try {
        $cell = IOFactory::load($tmpPath)->getActiveSheet()->getCell($coordinate);

        return ['type' => $cell->getDataType(), 'value' => $cell->getValue()];
    } finally {
        @unlink($tmpPath);
    }
}

test('the spreadsheet table does not lazy-load each lead\'s status one row at a time', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $leads = Lead::factory()->count(3)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Model::preventLazyLoading();

    try {
        Livewire::actingAs($clientAdmin)
            ->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')
            ->assertSee($leads->first()->identifier);
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('the xlsx export does not lazy-load each lead\'s status one row at a time', function () {
    $this->travelTo(Carbon::create(2026, 8, 30, 14, 32, 10));

    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Fb Lead Ads']);
    Lead::factory()->count(3)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Model::preventLazyLoading();

    try {
        Livewire::actingAs($clientAdmin)
            ->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')
            ->call('downloadXlsx')
            ->assertFileDownloaded('fb-lead-ads-2026-08-30_143210.xlsx');
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('a facebook lead ads lead\'s detail panel shows its submitted instant form fields', function () {
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
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSee('Contact Us')
        ->assertSee('Full Name')
        ->assertSee('City')
        ->assertSee('Dhaka');
});

test('the leads index shows one aggregate row per facebook lead ads source instead of one row per submission', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Fb Lead Ads']);
    Lead::factory()->count(3)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $websiteSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $websiteSource->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad');

    expect($component->get('facebookLeadAdsSources'))->toHaveCount(1);
    expect($component->get('facebookLeadAdsSources')->first()->leads_count)->toBe(3);
    expect($component->get('boardLeads')->collapse())->toHaveCount(1);
});

test('a second facebook lead ads source gets its own aggregate row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $sourceA = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Fb Lead Ads']);
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Fb Lead Ads (Campaign 2)']);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $sourceA->id]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $sourceB->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertSee('Fb Lead Ads')
        ->assertSee('Fb Lead Ads (Campaign 2)');
});

test('a non-admin agent only sees a facebook lead ads aggregate row for sources with a lead assigned to them', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $component = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad');

    expect($component->get('facebookLeadAdsSources'))->toHaveCount(0);

    $assignedLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'assigned_to' => $rep->id]);

    $component = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad');

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
        ->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')
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

    $component = Livewire::actingAs($clientAdmin)->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad');

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
        ->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')
        ->assertSee('Mine')
        ->assertDontSee('NotMine');

    expect($component->get('fieldColumns'))->toBe(['my_field']);
});

test('the spreadsheet view filters leads by a submitted-at date range, applied instantly with no search button', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    $oldLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Old Lead']);
    $oldLead->forceFill(['created_at' => now()->subDays(10)])->save();

    $recentLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Recent Lead']);
    $recentLead->forceFill(['created_at' => now()->subDay()])->save();

    $component = Livewire::actingAs($clientAdmin)
        ->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')
        ->set('fromDate', now()->subDays(3)->toDateString());

    expect($component->get('leads')->pluck('id'))->toContain($recentLead->id)->not->toContain($oldLead->id);

    $component->set('fromDate', '')
        ->set('toDate', now()->subDays(5)->toDateString());

    expect($component->get('leads')->pluck('id'))->toContain($oldLead->id)->not->toContain($recentLead->id);
});

test('the spreadsheet view has a case-insensitive search box that matches Lead ID only', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    $match = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'Jane Doe']);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'name' => 'John Smith']);

    $component = Livewire::actingAs($clientAdmin)
        ->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')
        ->set('search', strtolower($match->identifier));

    expect($component->get('leads')->pluck('id'))->toContain($match->id)->not->toContain($other->id);

    // Unlike the main Leads table, name is not searched here.
    $component->set('search', 'Jane Doe');
    expect($component->get('leads')->pluck('id'))->not->toContain($match->id);

    $component->set('search', '');
    expect($component->get('leads')->pluck('id'))->toContain($match->id)->toContain($other->id);
});

test('the spreadsheet view pushes its breadcrumb into the shared top bar exactly once', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Fb Lead Ads']);

    // A real HTTP request, not Livewire::test() — component-level tests
    // render only the component's own view, never the surrounding
    // layouts/app.blade.php that @stack('breadcrumbs') lives in, so this
    // is the only way to actually exercise the @push -> @stack path here.
    $response = $this->actingAs($clientAdmin)->get(route('tenant-leads.source', $source));

    $response->assertOk()->assertSeeInOrder(['Dashboard', 'Leads', 'Fb Lead Ads']);
    expect(substr_count($response->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);
});

test('the spreadsheet\'s mobile card shows only Lead ID, Status, and Submitted time, and links to the lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'name' => 'Jane Doe',
    ]);

    $html = Livewire::actingAs($clientAdmin)->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')->html();

    expect($html)->toContain('wire:key="fb-source-lead-card-'.$lead->id.'"');
    expect($html)->toContain('href="'.route('tenant-leads.show', $lead).'"');
    expect($html)->toContain($lead->identifier);
    expect($html)->toContain($lead->status->label());
});

test('the spreadsheet\'s mobile card shimmers a Lead ID inside an active meeting alert window, and is not muted otherwise', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['meeting_alert_enabled' => true, 'meeting_alert_days_before' => 3]);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $alertLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'meeting_date' => today()->addDay()]);
    $plainLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $html = Livewire::actingAs($clientAdmin)->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')->html();

    // Once for the mobile card, once for the desktop table — see the
    // "shimmering also work in desktop view" requirement this covers.
    expect(substr_count($html, 'lead-id-shimmer-text'))->toBe(2);
    expect($html)->not->toContain('font-mono text-[11px] text-zinc-400');
    expect($html)->toContain($plainLead->identifier);
});

test('the spreadsheet\'s desktop table also shimmers a Lead ID inside an active alert window (it never did before)', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tenant->update(['followup_alert_enabled' => true, 'followup_alert_days_before' => 3]);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'next_follow_up_at' => today()]);

    Livewire::actingAs($clientAdmin)
        ->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')
        ->assertSeeHtml('lead-id-shimmer-text')
        ->assertSee('Follow-up scheduled on');
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

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad');

    expect($component->instance()->sourceTypeOptions())->not->toContain(LeadSourceType::FacebookLeadAds->value);
});

test('the leads index shows a view all button (not delete) for a facebook lead ads source with leads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertSee('View All')
        ->assertDontSeeHtml('wire:click="confirmDeleteSource(\''.$source->id.'\')"');
});

test('the leads index shows a delete button (not view all) for an empty facebook lead ads source', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertSee('Delete')
        ->assertDontSee('View All');
});

test('the mobile card for a facebook lead ads source with leads has no View All button — the card links to it instead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $html = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->html();

    expect($html)->toContain('wire:key="fb-lead-ads-source-card-'.$source->id.'"');
    expect($html)->toContain('href="'.route('tenant-leads.source', $source).'"');

    // The desktop table (a separate block in the same response) still has
    // its own View All button for this source — one occurrence means the
    // mobile card didn't render a second copy of it.
    expect(substr_count($html, __('View All')))->toBe(1);
});

test('an empty facebook lead ads source\'s mobile card is not a link — there is nothing to view yet', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    $html = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad')->html();

    expect($html)->not->toContain('href="'.route('tenant-leads.source', $source).'"');

    // The desktop table (a separate block in the same response) also shows
    // a Delete button for an empty source — two occurrences (one per
    // button, mobile + desktop) confirms the mobile card still keeps its
    // own Delete action even though its View All is gone.
    expect(substr_count($html, 'wire:click="confirmDeleteSource(\''.$source->id.'\')"'))->toBe(2);
});

test('a facebook lead ads lead\'s "Back to Leads" button returns to its own source\'s spreadsheet, not the main table', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $content = $this->actingAs($clientAdmin)->get(route('tenant-leads.show', $lead))->assertOk()->getContent();

    expect($content)->toContain('href="'.route('tenant-leads.source', $source).'"');

    // The sidebar nav item and the breadcrumb ("Dashboard > Leads > ...")
    // both always link to tenant-leads.index regardless of source type, so
    // this only proves the *button* isn't also pointing there: exactly
    // those two occurrences, not a third for the button.
    expect(substr_count($content, 'href="'.route('tenant-leads.index').'"'))->toBe(2);
});

test('every other lead source type\'s "Back to Leads" button still returns to the main table', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $content = $this->actingAs($clientAdmin)->get(route('tenant-leads.show', $lead))->assertOk()->getContent();

    // Sidebar nav item + breadcrumb + button, all pointing at the main table.
    expect(substr_count($content, 'href="'.route('tenant-leads.index').'"'))->toBe(3);
});

test('a client admin can delete an empty facebook lead ads source', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('deleteSource', $source->id)
        ->assertDispatched('toast-show');

    expect(LeadSource::find($source->id))->toBeNull();
});

test('deleting an empty facebook lead ads source goes through the confirm modal', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('confirmDeleteSource', $source->id)
        ->assertSet('showDeleteSourceModal', true)
        ->assertSet('confirmingDeleteSourceId', $source->id)
        ->call('deleteSourceConfirmed')
        ->assertSet('showDeleteSourceModal', false);

    expect(LeadSource::find($source->id))->toBeNull();
});

test('deleting a facebook lead ads source that has leads is refused', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('deleteSource', $source->id);

    expect(LeadSource::find($source->id))->not->toBeNull();
});

test('a client user without delete permission cannot delete a facebook lead ads source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    Livewire::actingAs($rep)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('deleteSource', $source->id)
        ->assertDispatched('toast-show');

    expect(LeadSource::find($source->id))->not->toBeNull();
});

test('a client admin cannot delete another tenant\'s facebook lead ads source', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenantB->id, 'type' => LeadSourceType::FacebookLeadAds]);

    expect(fn () => Livewire::actingAs($clientAdminA)->test(Index::class)->call('completeInitialLoad')->call('deleteSource', $sourceB->id))
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
        ->test(SourceLeads::class, ['leadSource' => $source])->call('completeInitialLoad')
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

    expect($export->headings())->toBe(['Lead ID', 'Name', 'Full Name', 'City', 'Form', 'Status', 'Submitted At']);

    $row = $export->map($lead);

    expect($row[0])->toBe($lead->identifier);
    expect($row[1])->toBe('Jane Doe');
    expect($row[2])->toBe('Jane Doe');
    expect($row[3])->toBe('Dhaka');
    expect($row[4])->toBe('Contact Us');
});

test('a formula-like lead name is written as a literal string, not an executable formula', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => '=HYPERLINK("http://evil.example/","Click here")',
        'source_data' => ['field_data' => []],
    ]);

    $export = new FacebookLeadAdsExport(new Collection([$lead]), []);

    // Columns: A = Lead ID, B = Name (no dynamic field columns in this case).
    $cell = exportedCell($export, 'B2');

    expect($cell['type'])->toBe(DataType::TYPE_STRING);
    expect($cell['value'])->toBe('=HYPERLINK("http://evil.example/","Click here")');
});

test('a formula-like instant form field value is written as a literal string, not an executable formula', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Doe',
        'source_data' => [
            'field_data' => [
                ['name' => 'comment', 'values' => ['=SUM(1,2)']],
            ],
        ],
    ]);

    $export = new FacebookLeadAdsExport(new Collection([$lead]), ['comment']);

    // Columns: A = Lead ID, B = Name, C = Comment.
    $cell = exportedCell($export, 'C2');

    expect($cell['type'])->toBe(DataType::TYPE_STRING);
    expect($cell['value'])->toBe('=SUM(1,2)');
});

test('lead values beginning with +, -, or @ are written as literal strings with their exact content preserved', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => '+1 not a phone number, a note',
        'source_data' => [
            'field_data' => [
                ['name' => 'minus_note', 'values' => ['-1 is a negative comment, not a number']],
                ['name' => 'at_note', 'values' => ['@mention someone']],
            ],
        ],
    ]);

    $export = new FacebookLeadAdsExport(new Collection([$lead]), ['minus_note', 'at_note']);

    // Columns: A = Lead ID, B = Name, C = Minus Note, D = At Note.
    $name = exportedCell($export, 'B2');
    $minus = exportedCell($export, 'C2');
    $at = exportedCell($export, 'D2');

    expect($name['type'])->toBe(DataType::TYPE_STRING);
    expect($name['value'])->toBe('+1 not a phone number, a note');

    expect($minus['type'])->toBe(DataType::TYPE_STRING);
    expect($minus['value'])->toBe('-1 is a negative comment, not a number');

    expect($at['type'])->toBe(DataType::TYPE_STRING);
    expect($at['value'])->toBe('@mention someone');
});

test('normal lead name and field values are written unchanged as literal strings', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Doe',
        'source_data' => [
            'field_data' => [
                ['name' => 'city', 'values' => ['Dhaka']],
            ],
        ],
    ]);

    $export = new FacebookLeadAdsExport(new Collection([$lead]), ['city']);

    // Columns: A = Lead ID, B = Name, C = City.
    $name = exportedCell($export, 'B2');
    $city = exportedCell($export, 'C2');

    expect($name['type'])->toBe(DataType::TYPE_STRING);
    expect($name['value'])->toBe('Jane Doe');

    expect($city['type'])->toBe(DataType::TYPE_STRING);
    expect($city['value'])->toBe('Dhaka');
});
