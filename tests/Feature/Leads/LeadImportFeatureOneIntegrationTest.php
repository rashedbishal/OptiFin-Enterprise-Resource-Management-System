<?php

use App\Actions\Tenant\CreateLeadFromImport;
use App\Enums\LeadDuplicateLinkStatus;
use App\Enums\LeadImportRowStatus;
use App\Enums\LeadSourceType;
use App\Livewire\Tenant\LeadImports\Show;
use App\Models\Lead;
use App\Models\LeadDuplicateLink;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\LeadDuplicateDetectionService;
use App\Services\LeadMergeService;
use Livewire\Livewire;

/**
 * INC-1 E — closed by verification: the CSV importer and Feature #1 (duplicate
 * detection & merge review) are deliberately separate designs, and these tests
 * lock how they meet today.
 *
 *  - A lead CREATED by an import is an ordinary lead to Feature #1: it is found
 *    when a later lead shares its normalized email/phone, both live
 *    (detectFor(), which the lead detail page runs) and by the scheduled
 *    leads:detect-duplicates sweep.
 *  - A row SKIPPED as a duplicate is only a LeadImportRow. A LeadDuplicateLink
 *    needs two lead rows, so no kind of skipped row (existing-lead duplicate,
 *    partial match, A1 conflict, in-file duplicate, merged-identity alias)
 *    creates one, creates a lead, or changes an existing link. The import
 *    itself never runs or enqueues detection.
 */

/**
 * @return array{tenant: Tenant, admin: TenantUser, csv: LeadSource, manual: LeadSource}
 */
function featureOneImportContext(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();

    return [
        'tenant' => $tenant,
        'admin' => $admin,
        'csv' => LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport, 'name' => 'CSV Import']),
        'manual' => LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]),
    ];
}

/**
 * @param  array<string, mixed>  $row
 */
function featureOneImportRow(array $ctx, array $row, int $rowNumber = 1, ?LeadImport $import = null): LeadImportRow
{
    $import ??= LeadImport::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['csv']->id, 'imported_by' => $ctx['admin']->id]);

    app(CreateLeadFromImport::class)->handle($import, $ctx['tenant'], $ctx['csv'], $ctx['admin'], $rowNumber, $row);

    return LeadImportRow::where('lead_import_id', $import->id)->where('row_number', $rowNumber)->firstOrFail();
}

test('a lead created by a CSV import is found by Feature #1 when a later lead shares its normalized email or phone', function (string $field) {
    $ctx = featureOneImportContext();
    $imported = Lead::findOrFail(featureOneImportRow($ctx, ['name' => 'Imported Ivan', 'email' => 'Ivan@Synthetic.test', 'phone' => '+1 (555) 200-3000'])->lead_id);

    expect($imported->source->type)->toBe(LeadSourceType::CsvImport);
    expect(LeadDuplicateLink::count())->toBe(0); // importing runs no detection

    $later = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['manual']->id, 'name' => 'Manual Ivan'] + match ($field) {
        'email' => ['email' => 'ivan@synthetic.test', 'phone' => null],
        'phone' => ['email' => null, 'phone' => '15552003000'],
    });

    // From the later lead's side (what its detail page runs) ...
    $links = app(LeadDuplicateDetectionService::class)->detectFor($later);

    expect($links)->toHaveCount(1);
    expect($links->first()->status)->toBe(LeadDuplicateLinkStatus::Pending);
    expect($links->first()->matched_fields)->toBe([$field]);
    expect($links->first()->otherLead($later)->is($imported))->toBeTrue();

    // ... and from the imported lead's own side: the same single pair, never a second row.
    expect(app(LeadDuplicateDetectionService::class)->detectFor($imported))->toHaveCount(1);
    expect(LeadDuplicateLink::count())->toBe(1);
})->with(['email' => ['email'], 'phone' => ['phone']]);

test('the scheduled leads:detect-duplicates sweep finds an imported lead\'s duplicate even if nobody opens either lead, idempotently and within the tenant', function () {
    $ctx = featureOneImportContext();
    $imported = Lead::findOrFail(featureOneImportRow($ctx, ['name' => 'Imported Ivan', 'email' => 'ivan@synthetic.test'])->lead_id);
    $later = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['manual']->id, 'name' => 'Manual Ivan', 'email' => 'IVAN@synthetic.test', 'phone' => null]);

    // Same identity in ANOTHER tenant must never be linked to this pair.
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Other Tenant Ivan', 'email' => 'ivan@synthetic.test', 'phone' => null]);

    expect(LeadDuplicateLink::count())->toBe(0);

    $this->artisan('leads:detect-duplicates')->expectsOutputToContain('Scanned')->assertSuccessful();

    $link = LeadDuplicateLink::sole();
    expect($link->tenant_id)->toBe($ctx['tenant']->id);
    expect($link->status)->toBe(LeadDuplicateLinkStatus::Pending);
    expect([$link->lead_id, $link->duplicate_lead_id])->toEqualCanonicalizing([$imported->id, $later->id]);
    expect($link->matched_fields)->toBe(['email']);

    // A second sweep re-flags nothing.
    $this->artisan('leads:detect-duplicates')->assertSuccessful();
    expect(LeadDuplicateLink::count())->toBe(1);
});

test('the sweep scans imported leads themselves: two imported leads that later come to share an identity are flagged', function () {
    $ctx = featureOneImportContext();
    $first = Lead::findOrFail(featureOneImportRow($ctx, ['name' => 'First Person', 'email' => 'first@synthetic.test'], 1)->lead_id);
    $second = Lead::findOrFail(featureOneImportRow($ctx, ['name' => 'Second Person', 'email' => 'second@synthetic.test'], 2)->lead_id);

    // An edit after the import makes them share an email — the importer could never have seen this.
    $second->update(['email' => 'FIRST@synthetic.test']);
    expect(LeadDuplicateLink::count())->toBe(0);

    $this->artisan('leads:detect-duplicates')->assertSuccessful();

    expect([LeadDuplicateLink::sole()->lead_id, LeadDuplicateLink::sole()->duplicate_lead_id])->toEqualCanonicalizing([$first->id, $second->id]);
});

test('a CSV import itself never writes a duplicate link, even when several leads are imported', function () {
    $ctx = featureOneImportContext();
    $import = LeadImport::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['csv']->id, 'imported_by' => $ctx['admin']->id]);

    foreach ([['name' => 'First Person', 'email' => 'first@synthetic.test'], ['name' => 'Second Person', 'phone' => '5551110002'], ['name' => 'No Contact']] as $i => $row) {
        expect(featureOneImportRow($ctx, $row, $i + 1, $import)->status)->toBe(LeadImportRowStatus::Imported);
    }

    expect(Lead::where('tenant_id', $ctx['tenant']->id)->count())->toBe(3);
    expect(LeadDuplicateLink::count())->toBe(0);
});

test('no kind of skipped row creates a lead or a duplicate link: it stays only a LeadImportRow', function () {
    $ctx = featureOneImportContext();
    $tenantId = $ctx['tenant']->id;
    $manual = fn (array $attributes) => Lead::factory()->create(['tenant_id' => $tenantId, 'source_id' => $ctx['manual']->id] + $attributes);

    $existing = $manual(['name' => 'Existing Ada', 'email' => 'ada@synthetic.test', 'phone' => '5551110001']);
    $other = $manual(['name' => 'Existing Bob', 'email' => 'bob@synthetic.test', 'phone' => '5552220002']);
    $survivor = $manual(['name' => 'Survivor Sam', 'email' => 'sam@synthetic.test', 'phone' => null]);
    $loser = $manual(['name' => 'Loser Lee', 'email' => 'lee@synthetic.test', 'phone' => null]);
    app(LeadMergeService::class)->merge($survivor, $loser, $ctx['admin'], []);

    $import = LeadImport::factory()->create(['tenant_id' => $tenantId, 'source_id' => $ctx['csv']->id, 'imported_by' => $ctx['admin']->id]);
    $leadsBefore = Lead::where('tenant_id', $tenantId)->count();
    $linksBefore = LeadDuplicateLink::count(); // the one merged link created by the merge above

    $skipped = [
        'existing-lead duplicate' => ['name' => 'Ada Again', 'email' => 'ada@synthetic.test'],
        'partial match (INC-1 C)' => ['name' => 'Ada Partial', 'email' => 'ada@synthetic.test', 'phone' => '+44 20 7946 0001'],
        'A1 conflict' => ['name' => 'Mixed Up', 'email' => 'ada@synthetic.test', 'phone' => '5552220002'],
        'merged identity (INC-1 D)' => ['name' => 'Lee Again', 'email' => 'lee@synthetic.test'],
    ];

    $rows = [];
    $n = 0;

    foreach ($skipped as $label => $row) {
        $rows[$label] = featureOneImportRow($ctx, $row, ++$n, $import);
        expect($rows[$label]->status)->toBe(LeadImportRowStatus::Duplicate, $label);
        expect($rows[$label]->lead_id)->toBeNull($label);
    }

    // in-file duplicate: row 1 is imported, row 2 repeats it
    featureOneImportRow($ctx, ['name' => 'Fresh Person', 'email' => 'fresh@synthetic.test'], ++$n, $import);
    $inFile = featureOneImportRow($ctx, ['name' => 'Fresh Again', 'email' => 'fresh@synthetic.test'], ++$n, $import);
    expect($inFile->status)->toBe(LeadImportRowStatus::Duplicate)->and($inFile->duplicate_of_lead_id)->toBeNull()->and($inFile->lead_id)->toBeNull();

    // INC-1 C and D behavior is exactly what it was: the same targets and messages
    expect($rows['partial match (INC-1 C)']->duplicate_of_lead_id)->toBe($existing->id);
    expect($rows['A1 conflict']->duplicate_of_lead_id)->toBeNull();
    expect($rows['merged identity (INC-1 D)']->duplicate_of_lead_id)->toBe($survivor->id);

    // Only the one non-duplicate row became a lead; no row created a link.
    expect(Lead::where('tenant_id', $tenantId)->count())->toBe($leadsBefore + 1);
    expect(LeadDuplicateLink::count())->toBe($linksBefore);
    expect(LeadImportRow::where('lead_import_id', $import->id)->where('status', LeadImportRowStatus::Duplicate)->count())->toBe(5);

    // Nor does Feature #1 ever see a skipped row, live or by the sweep.
    expect(app(LeadDuplicateDetectionService::class)->detectFor($existing))->toHaveCount(0);
    expect(app(LeadDuplicateDetectionService::class)->detectFor($survivor))->toHaveCount(0);
    $this->artisan('leads:detect-duplicates')->assertSuccessful();
    expect(LeadDuplicateLink::count())->toBe($linksBefore);
    expect(LeadDuplicateLink::where('status', LeadDuplicateLinkStatus::Pending)->count())->toBe(0);
    expect($other->fresh()->only(['email', 'phone']))->toBe(['email' => 'bob@synthetic.test', 'phone' => '5552220002']);
});

test('a skipped row leaves an existing pending Feature #1 pair untouched, and the import page still shows the C label and the D alias', function () {
    $ctx = featureOneImportContext();
    $pairA = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['manual']->id, 'name' => 'Pair One', 'email' => 'pair@synthetic.test', 'phone' => null]);
    $pairB = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['manual']->id, 'name' => 'Pair Two', 'email' => 'pair@synthetic.test', 'phone' => null]);
    app(LeadDuplicateDetectionService::class)->detectFor($pairA);
    $pending = LeadDuplicateLink::sole();
    $before = $pending->only(['status', 'matched_fields', 'resolved_by', 'resolved_at', 'field_resolutions']);

    // A row whose partial match hits Pair One only, plus a row for a merged loser's identity
    $survivor = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['manual']->id, 'name' => 'Survivor Sam', 'email' => 'sam@synthetic.test', 'phone' => null]);
    $loser = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['manual']->id, 'name' => 'Loser Lee', 'email' => 'lee@synthetic.test', 'phone' => null]);
    app(LeadMergeService::class)->merge($survivor, $loser, $ctx['admin'], []);
    $linksBefore = LeadDuplicateLink::count();

    $lone = Lead::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['manual']->id, 'name' => 'Lone Ada', 'email' => 'ada@synthetic.test', 'phone' => null]);
    $import = LeadImport::factory()->create(['tenant_id' => $ctx['tenant']->id, 'source_id' => $ctx['csv']->id, 'imported_by' => $ctx['admin']->id]);
    $partial = featureOneImportRow($ctx, ['name' => 'Ada Partial', 'email' => 'ada@synthetic.test', 'phone' => '+44 20 7946 0001'], 1, $import);
    $alias = featureOneImportRow($ctx, ['name' => 'Lee Again', 'email' => 'lee@synthetic.test'], 2, $import);

    expect($partial->duplicate_of_lead_id)->toBe($lone->id)->and($alias->duplicate_of_lead_id)->toBe($survivor->id);
    expect(LeadDuplicateLink::count())->toBe($linksBefore);
    expect($pending->fresh()->only(['status', 'matched_fields', 'resolved_by', 'resolved_at', 'field_resolutions']))->toBe($before);
    expect($pending->fresh()->status)->toBe(LeadDuplicateLinkStatus::Pending);

    Livewire::actingAs($ctx['admin'])->test(Show::class, ['leadImport' => $import->fresh()])
        ->assertSee('Matched by: Email')
        ->assertSee('Unmatched phone: +44 20 7946 0001')
        ->assertSee('Survivor Sam');
});
