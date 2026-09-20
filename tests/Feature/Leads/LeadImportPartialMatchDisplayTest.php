<?php

use App\Actions\Tenant\CreateLeadFromImport;
use App\Enums\LeadImportRowStatus;
use App\Enums\LeadSourceType;
use App\Livewire\Tenant\LeadImports\Show;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * INC-1 C — the import detail page says which incoming identity field matched
 * an existing lead and which incoming value did not. Everything is derived at
 * render time from data the page already loads: no importer change, no
 * persisted state, no additional query, and nothing about the existing lead
 * beyond the pre-existing "Duplicate of <name>" link is disclosed.
 *
 * Rows are produced by the real CreateLeadFromImport so the rendered state is
 * exactly what an import leaves behind.
 */

/**
 * @return array{tenant: Tenant, admin: TenantUser, source: LeadSource, import: LeadImport, existing: Lead}
 */
function partialMatchFixture(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport, 'name' => 'CSV Import']);
    $import = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'imported_by' => $admin->id]);
    $existing = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'name' => 'Existing Ada',
        'email' => 'ada@synthetic.test',
        'phone' => '(555) 111-0001',
        'company' => 'Ada Confidential Ltd',
        'location' => 'Ada Secret City',
    ]);

    return compact('tenant', 'admin', 'source', 'import', 'existing');
}

/**
 * @param  array<string, mixed>  $row
 */
function importPartialMatchRow(array $fixture, int $rowNumber, array $row): LeadImportRow
{
    app(CreateLeadFromImport::class)->handle($fixture['import'], $fixture['tenant'], $fixture['source'], $fixture['admin'], $rowNumber, $row);

    return LeadImportRow::where('lead_import_id', $fixture['import']->id)->where('row_number', $rowNumber)->firstOrFail();
}

function renderImportDetail(array $fixture): Testable
{
    return Livewire::actingAs($fixture['admin'])->test(Show::class, ['leadImport' => $fixture['import']->fresh()]);
}

test('email-only partial match: shows that email matched and the incoming phone that did not', function () {
    $fixture = partialMatchFixture();
    $row = importPartialMatchRow($fixture, 1, ['name' => 'Ada Dup', 'email' => 'ADA@synthetic.test', 'phone' => '+44 20 7946 0001']);

    expect($row->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($row->duplicate_of_lead_id)->toBe($fixture['existing']->id);

    renderImportDetail($fixture)
        ->assertSee('Duplicate of')
        ->assertSee('Existing Ada')
        ->assertSee('Matched by: Email')
        ->assertDontSee('Matched by: Email + Phone')
        ->assertSee('Unmatched phone: +44 20 7946 0001')
        ->assertDontSee('Unmatched email');
});

test('phone-only partial match: shows that phone matched and the incoming email that did not', function () {
    $fixture = partialMatchFixture();
    importPartialMatchRow($fixture, 1, ['name' => 'Ada Phone Dup', 'email' => 'brand-new@synthetic.test', 'phone' => '555 111 0001']);

    renderImportDetail($fixture)
        ->assertSee('Matched by: Phone')
        ->assertDontSee('Matched by: Email')
        ->assertSee('Unmatched email: brand-new@synthetic.test')
        ->assertDontSee('Unmatched phone');
});

test('a partial match with nothing else to compare shows only what matched', function () {
    $fixture = partialMatchFixture();
    importPartialMatchRow($fixture, 1, ['name' => 'Email Only', 'email' => 'ada@synthetic.test', 'phone' => '']);
    importPartialMatchRow($fixture, 2, ['name' => 'Phone Only', 'email' => '', 'phone' => '5551110001']);

    renderImportDetail($fixture)
        ->assertSee('Matched by: Email')
        ->assertSee('Matched by: Phone')
        ->assertDontSee('Unmatched');
});

test('both identifiers matching the same lead shows both matched and no unmatched value', function () {
    $fixture = partialMatchFixture();
    importPartialMatchRow($fixture, 1, ['name' => 'Ada Both', 'email' => 'ada@synthetic.test', 'phone' => '555-111-0001']);

    renderImportDetail($fixture)
        ->assertSee('Matched by: Email + Phone')
        ->assertDontSee('Unmatched');
});

test('a row that matches nothing is imported as usual and gets no partial-match detail', function () {
    $fixture = partialMatchFixture();
    $row = importPartialMatchRow($fixture, 1, ['name' => 'Fresh Person', 'email' => 'fresh@synthetic.test', 'phone' => '5554445555']);

    expect($row->status)->toBe(LeadImportRowStatus::Imported);

    renderImportDetail($fixture)
        ->assertSee('Fresh Person')
        ->assertSee('View lead')
        ->assertDontSee('Matched by')
        ->assertDontSee('Unmatched');
});

test('conflict rows, in-file duplicates and failed rows keep their existing message and get no derived detail', function () {
    $fixture = partialMatchFixture();
    Lead::factory()->create(['tenant_id' => $fixture['tenant']->id, 'source_id' => $fixture['source']->id, 'name' => 'Existing Bob', 'email' => 'bob@synthetic.test', 'phone' => '555-111-0002']);

    $conflict = importPartialMatchRow($fixture, 1, ['name' => 'Mixed', 'email' => 'ada@synthetic.test', 'phone' => '5551110002']);
    importPartialMatchRow($fixture, 2, ['name' => 'First', 'email' => 'zed@synthetic.test', 'phone' => '5556661111']);
    importPartialMatchRow($fixture, 3, ['name' => 'Second', 'email' => 'zed@synthetic.test', 'phone' => '5556662222']);
    importPartialMatchRow($fixture, 4, ['name' => '', 'email' => 'nameless@synthetic.test']);

    expect($conflict->duplicate_of_lead_id)->toBeNull();

    renderImportDetail($fixture)
        ->assertSee('Email matches an existing lead and phone matches a different existing lead')
        ->assertSee('Duplicate of an earlier row in this same CSV file.')
        ->assertDontSee('Matched by')
        ->assertDontSee('Unmatched');
});

test('only the incoming identity values are surfaced — no other skipped-row column and no other detail of the existing lead', function () {
    $fixture = partialMatchFixture();
    importPartialMatchRow($fixture, 1, [
        'name' => 'Ada Dup',
        'email' => 'ada@synthetic.test',
        'phone' => '+44 20 7946 0001',
        'company' => 'Incoming Brand New Co',
        'location' => 'Incoming New City',
        'budget' => '987654',
        'tags' => 'incoming-tag',
    ]);

    renderImportDetail($fixture)
        ->assertSee('Unmatched phone: +44 20 7946 0001')
        // skipped-row columns beyond identity are not newly surfaced
        ->assertDontSee('Incoming Brand New Co')
        ->assertDontSee('Incoming New City')
        ->assertDontSee('987654')
        ->assertDontSee('incoming-tag')
        // nothing new about the existing lead: not its company, location, phone or email
        ->assertDontSee('Ada Confidential Ltd')
        ->assertDontSee('Ada Secret City')
        ->assertDontSee('(555) 111-0001')
        ->assertDontSee('5551110001')
        ->assertDontSee('ada@synthetic.test');
});

test('editing the linked lead after the import never produces a misleading label', function () {
    $fixture = partialMatchFixture();
    importPartialMatchRow($fixture, 1, ['name' => 'Ada Dup', 'email' => 'ada@synthetic.test', 'phone' => '+44 20 7946 0001']);

    $fixture['existing']->update(['email' => 'changed@synthetic.test']);

    renderImportDetail($fixture)
        ->assertSee('Duplicate of')
        ->assertDontSee('Matched by')
        ->assertDontSee('Unmatched');
});

test('rendering and importing never change an existing lead or create a duplicate lead', function () {
    $fixture = partialMatchFixture();
    $before = Lead::query()->orderBy('id')->get()->map->getAttributes()->all();

    importPartialMatchRow($fixture, 1, ['name' => 'Ada Dup', 'email' => 'ada@synthetic.test', 'phone' => '+44 20 7946 0001', 'company' => 'Another Co']);
    importPartialMatchRow($fixture, 2, ['name' => 'Ada Phone Dup', 'email' => 'brand-new@synthetic.test', 'phone' => '5551110001']);
    renderImportDetail($fixture);

    expect(Lead::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
    expect(Lead::count())->toBe(1);
    expect($fixture['import']->fresh()->only(['imported_count', 'duplicate_count', 'failed_count']))->toBe(['imported_count' => 0, 'duplicate_count' => 2, 'failed_count' => 0]);
});

test('re-running an already recorded row is still a no-op and renders identically', function () {
    $fixture = partialMatchFixture();
    $row = ['name' => 'Ada Dup', 'email' => 'ada@synthetic.test', 'phone' => '+44 20 7946 0001'];
    importPartialMatchRow($fixture, 1, $row);
    $html = renderImportDetail($fixture)->html();

    importPartialMatchRow($fixture, 1, $row);

    expect(LeadImportRow::where('lead_import_id', $fixture['import']->id)->count())->toBe(1);
    expect($fixture['import']->fresh()->duplicate_count)->toBe(1);
    renderImportDetail($fixture)->assertSee('Unmatched phone: +44 20 7946 0001');
    expect(Lead::count())->toBe(1);
    expect($html)->toContain('Matched by: Email');
});

test('another tenant cannot open the import detail, and a matching lead in another tenant is never matched or shown', function () {
    $fixture = partialMatchFixture();
    importPartialMatchRow($fixture, 1, ['name' => 'Ada Dup', 'email' => 'ada@synthetic.test', 'phone' => '+44 20 7946 0001']);

    ['clientAdmin' => $otherAdmin, 'tenant' => $otherTenant] = tenantWithLeadManagement();
    Livewire::actingAs($otherAdmin)->test(Show::class, ['leadImport' => $fixture['import']])->assertStatus(404);

    // The same identity in the OTHER tenant's own import: detection is tenant-scoped, so it imports as new
    // and nothing from the first tenant (the lead's name included) appears.
    $otherSource = LeadSource::factory()->create(['tenant_id' => $otherTenant->id, 'type' => LeadSourceType::CsvImport, 'name' => 'CSV Import']);
    $otherImport = LeadImport::factory()->create(['tenant_id' => $otherTenant->id, 'source_id' => $otherSource->id, 'imported_by' => $otherAdmin->id]);
    app(CreateLeadFromImport::class)->handle($otherImport, $otherTenant, $otherSource, $otherAdmin, 1, ['name' => 'Other Tenant Row', 'email' => 'ada@synthetic.test', 'phone' => '+44 20 7946 0001']);

    Livewire::actingAs($otherAdmin)->test(Show::class, ['leadImport' => $otherImport->fresh()])
        ->assertSee('Other Tenant Row')
        ->assertDontSee('Existing Ada')
        ->assertDontSee('Matched by')
        ->assertDontSee('Unmatched');
});

test('the detail page issues no additional queries per partial-match row', function () {
    $countQueries = function (int $duplicateRows): int {
        $fixture = partialMatchFixture();

        foreach (range(1, $duplicateRows) as $n) {
            importPartialMatchRow($fixture, $n, ['name' => "Dup {$n}", 'email' => 'ada@synthetic.test', 'phone' => '+44 20 7946 000'.$n]);
        }

        $component = Livewire::actingAs($fixture['admin'])->test(Show::class, ['leadImport' => $fixture['import']->fresh()]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('$refresh');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($countQueries(8))->toBe($countQueries(1));
});

test('incoming values are HTML-escaped when rendered', function () {
    $fixture = partialMatchFixture();

    // Validation would reject markup in a real CSV row, so the row is written directly: this locks the
    // rendering contract itself — whatever is stored is escaped, never emitted as markup.
    LeadImportRow::factory()->create([
        'lead_import_id' => $fixture['import']->id,
        'row_number' => 1,
        'status' => LeadImportRowStatus::Duplicate,
        'duplicate_of_lead_id' => $fixture['existing']->id,
        'normalized_email' => 'ada@synthetic.test',
        'normalized_phone' => '999',
        'raw_data' => ['name' => 'Escaped', 'email' => 'ada@synthetic.test', 'phone' => '<b>injected</b>'],
    ]);

    $html = renderImportDetail($fixture)->html();

    expect($html)->toContain('Unmatched phone: &lt;b&gt;injected&lt;/b&gt;')->not->toContain('<b>injected</b>');
});
