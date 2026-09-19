<?php

use App\Enums\LeadImportRowStatus;
use App\Enums\LeadImportStatus;
use App\Livewire\Tenant\LeadImports\Index;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * End-to-end tests of the REAL App\Imports\LeadCsvImport engine — no
 * Excel::fake() here, deliberately: QUEUE_CONNECTION=sync in the test
 * environment (phpunit.xml) means a queued job's chain runs synchronously,
 * in-process, immediately on dispatch. This is what actually exercises
 * Laravel Excel's real chunk-chaining/finalization mechanics (verified
 * against the installed 4.0.2 source — see LeadCsvImport's own docblock),
 * not just App\Actions\Tenant\CreateLeadFromImport in isolation.
 *
 * Storage::fake('local') still points Laravel Excel's own file reading at
 * a real (test-scoped) path under storage/framework/testing/, so the
 * actual chunked read still works — it just keeps the uploaded/stored CSV
 * out of the real storage/app/private directory. See LeadImportUploadTest
 * for why this is required, not optional.
 */
beforeEach(fn () => Storage::fake('local'));
test('a full CSV upload runs end to end: rows are created, and the import reaches Completed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $csv = "name,email\nAlice,alice@example.com\nBob,bob@example.com\nCarol,carol@example.com\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('leads.csv', $csv))
        ->call('import');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();

    expect($import->status)->toBe(LeadImportStatus::Completed);
    expect($import->imported_count)->toBe(3);
    expect($import->duplicate_count)->toBe(0);
    expect($import->failed_count)->toBe(0);
    expect($import->completed_at)->not->toBeNull();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(3);
    expect(LeadImportRow::where('lead_import_id', $import->id)->count())->toBe(3);

    foreach (LeadImportRow::where('lead_import_id', $import->id)->get() as $row) {
        expect($row->status)->toBe(LeadImportRowStatus::Imported);
    }
});

test('a mix of valid, duplicate, and invalid rows ends in CompletedWithErrors with accurate counts', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'existing@example.com']);

    $csv = implode("\n", [
        'name,email',
        'Valid One,valid1@example.com',
        ',missingname@example.com', // invalid: name required
        'Existing Dupe,existing@example.com', // duplicate of a pre-existing lead
        'Valid Two,valid2@example.com',
    ])."\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('mixed.csv', $csv))
        ->call('import');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();

    expect($import->status)->toBe(LeadImportStatus::CompletedWithErrors);
    expect($import->imported_count)->toBe(2);
    expect($import->duplicate_count)->toBe(1);
    expect($import->failed_count)->toBe(1);
});

test('deterministic row order holds end to end: the lowest CSV row number wins an in-file duplicate, regardless of chunking', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    // Row 2 (first data row) and row 3 share an email — row 2 must win.
    $csv = "name,email\nFirst Winner,shared@example.com\nSecond Loser,shared@example.com\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('order.csv', $csv))
        ->call('import');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();

    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect(Lead::where('tenant_id', $tenant->id)->first()->name)->toBe('First Winner');

    $rowOne = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 1)->firstOrFail();
    $rowTwo = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 2)->firstOrFail();
    expect($rowOne->status)->toBe(LeadImportRowStatus::Imported);
    expect($rowTwo->status)->toBe(LeadImportRowStatus::Duplicate);
});

/**
 * M6 — an import spanning more than one chunk (chunkSize() = 200) must
 * still process every row correctly: LeadCsvImport's per-chunk lazy cache
 * (resolvedImport/resolvedTenant/resolvedSource) resets to null on every
 * fresh chunk-job instance, so this proves that reset — and the re-resolve
 * it triggers — doesn't skip, duplicate, or misattribute any row crossing
 * the boundary. 250 rows = exactly 2 chunks (rows 1-200, 201-250).
 */
test('an import spanning more than one chunk (250 rows) processes every row correctly across the chunk boundary', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $letters = range('a', 'z');
    $lines = ['name,email'];
    for ($i = 0; $i < 250; $i++) {
        $suffix = $letters[$i % 26].$letters[intdiv($i, 26) % 26];
        $lines[] = "Person {$suffix},person{$i}@chunkboundary.test";
    }
    $csv = implode("\n", $lines)."\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('chunked.csv', $csv))
        ->call('import');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();

    expect($import->status)->toBe(LeadImportStatus::Completed);
    expect($import->imported_count)->toBe(250);
    expect($import->duplicate_count)->toBe(0);
    expect($import->failed_count)->toBe(0);
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(250);
    expect(LeadImportRow::where('lead_import_id', $import->id)->where('status', LeadImportRowStatus::Imported)->count())->toBe(250);

    // Every row number 1-250 present exactly once — no row skipped or
    // reprocessed at the chunk boundary (row 200/201).
    $rowNumbers = LeadImportRow::where('lead_import_id', $import->id)->pluck('row_number')->sort()->values()->all();
    expect($rowNumbers)->toBe(range(1, 250));
});

/**
 * M6 — real query-count regression guard, run through the exact same
 * production entry point (Livewire upload -> LeadImport::create() ->
 * Excel::import()) every other test in this file uses. Threshold reflects
 * the APPROVED, safer 3-of-4-cached design (TenantUser intentionally
 * excluded) — measured directly against this same real Postgres
 * connection: 20 simple rows produce 299 total queries (14.95/row)
 * through this exact Livewire flow before DB-2.
 *
 * DB-2 — closing the CSV-import concurrent-duplicate-email race required
 * an authoritative checkExistingLeadDuplicate() re-check inside the
 * per-row transaction, immediately after Tenant::lockForUpdate() (see
 * App\Actions\Tenant\CreateLeadFromImport::processRow()'s own DB-2
 * comment). For this fixture every row is email-only (no phone), so the
 * re-check costs exactly one extra query per row: 299 + 20 = 319. This is
 * deterministic — not a flaky or environment-dependent measurement — and
 * verified by direct git-stash A/B comparison (pre-DB-2: 299, post-DB-2:
 * 319, every run). The baseline below is updated to 319 accordingly.
 *
 * The threshold is deliberately tighter than a generic "leaves some
 * headroom" margin: it's sized to actually catch BOTH halves of the M6
 * optimization being independently reverted, not just a full reversion —
 * DB-2's fixed +20 shifts every one of these figures by the same +20 but
 * changes none of the underlying reasoning. For this same 20-row fixture,
 * with DB-2's re-check still in place:
 *   - reverting only the LeadCsvImport per-chunk caching (LeadImport/
 *     Tenant/LeadSource) adds back 3 x (20 - 1) = 57 queries -> 319 + 57 = 376
 *   - reverting only the computeFor()/setRelation() change adds back
 *     2 x 20 = 40 queries -> 319 + 40 = 359
 * A looser threshold would let the second, smaller regression pass
 * silently. 330 sits strictly between the new measured optimized result
 * (319) and the smaller of the two regression totals (359) — the same
 * +11-above-baseline, 29-below-nearest-regression margin the original
 * 299/310/339 figures used — so either regression alone, on top of DB-2,
 * still fails this assertion.
 */
test('a moderate CSV import stays within the expected post-optimization query budget', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $letters = range('a', 'z');
    $lines = ['name,email'];
    for ($i = 0; $i < 20; $i++) {
        $suffix = $letters[$i % 26].$letters[intdiv($i, 26) % 26];
        $lines[] = "Person {$suffix},person{$i}@querybudget.test";
    }
    $csv = implode("\n", $lines)."\n";

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('budget.csv', $csv))
        ->call('import');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();
    expect($import->imported_count)->toBe(20);

    // Measured result through this exact Livewire flow, post-DB-2: 319
    // (299 pre-DB-2 + 20 for the mandatory in-lock duplicate re-check,
    // one extra query per email-only row). 330 is deliberately tight
    // enough to catch either half of the M6 optimization being reverted
    // alone (~359 and ~376 — see this test's own docblock above for the
    // exact derivation), not just a full reversion back toward the
    // pre-optimization ~19-20/row (~380-400 total) baseline.
    expect($queryCount)->toBeLessThan(330);
});

/**
 * INC-1 A1 — an in-file conflict (email matches one earlier Imported row,
 * phone matches a different earlier Imported row) end to end through the real
 * engine: recorded as a Duplicate row with the in-file conflict message, no
 * Lead created, counters accurate, and ordinary in-file duplicates unchanged.
 */
test('an in-file conflict is recorded end to end with accurate counters, and ordinary in-file duplicates are unchanged', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $csv = implode("\n", [
        'name,email,phone',
        'Alpha,alpha@example.com,',
        'Bravo,,5550001234',
        'Charlie,alpha@example.com,5550001234',
        'Delta,alpha@example.com,5550009999',
        'Echo,echo@example.com,',
    ])."\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('conflict.csv', $csv))
        ->call('import');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();

    expect($import->status)->toBe(LeadImportStatus::Completed);
    expect($import->only(['imported_count', 'duplicate_count', 'failed_count']))->toBe(['imported_count' => 3, 'duplicate_count' => 2, 'failed_count' => 0]);
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(3);

    $rows = LeadImportRow::where('lead_import_id', $import->id)->orderBy('row_number')->get()->keyBy('row_number');
    expect($rows[3]->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($rows[3]->errors)->toBe(['Email matches an earlier row in this CSV file and phone matches a different earlier row — rejected as a duplicate conflict.']);
    expect($rows[3]->duplicate_of_lead_id)->toBeNull();
    expect($rows[4]->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($rows[4]->errors)->toBe(['Duplicate of an earlier row in this same CSV file.']);
    expect($rows[5]->status)->toBe(LeadImportRowStatus::Imported);
});

test('an in-file conflict is detected across the chunk boundary: the earlier rows sit in chunk 1 and the conflicting row in chunk 2', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $letters = range('a', 'z');
    $lines = ['name,email,phone'];
    for ($i = 1; $i <= 250; $i++) {
        $suffix = $letters[$i % 26].$letters[intdiv($i, 26) % 26];

        $lines[] = match ($i) {
            1 => 'Person Alpha,boundary-a@example.com,',
            2 => 'Person Bravo,,5550001234',
            201 => 'Person Conflict,boundary-a@example.com,5550001234',
            default => "Person {$suffix},filler{$i}@boundary.test,",
        };
    }

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('boundary-conflict.csv', implode("\n", $lines)."\n"))
        ->call('import');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();

    expect($import->status)->toBe(LeadImportStatus::Completed);
    expect($import->only(['imported_count', 'duplicate_count', 'failed_count']))->toBe(['imported_count' => 249, 'duplicate_count' => 1, 'failed_count' => 0]);
    expect(Lead::where('tenant_id', $tenant->id)->count())->toBe(249);

    $conflict = LeadImportRow::where('lead_import_id', $import->id)->where('row_number', 201)->firstOrFail();
    expect($conflict->status)->toBe(LeadImportRowStatus::Duplicate);
    expect($conflict->errors)->toBe(['Email matches an earlier row in this CSV file and phone matches a different earlier row — rejected as a duplicate conflict.']);
    expect(LeadImportRow::where('lead_import_id', $import->id)->count())->toBe(250);
});
