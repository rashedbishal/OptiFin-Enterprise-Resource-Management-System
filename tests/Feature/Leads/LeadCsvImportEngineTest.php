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
 * through this exact Livewire flow.
 *
 * The threshold is deliberately tighter than a generic "leaves some
 * headroom" margin: it's sized to actually catch BOTH halves of the M6
 * optimization being independently reverted, not just a full reversion.
 * For this same 20-row fixture:
 *   - reverting only the LeadCsvImport per-chunk caching (LeadImport/
 *     Tenant/LeadSource) adds back 3 x (20 - 1) = 57 queries -> ~356
 *   - reverting only the computeFor()/setRelation() change adds back
 *     2 x 20 = 40 queries -> ~339
 * A looser threshold (e.g. 340) would let the second, smaller regression
 * pass silently. 310 sits strictly between the measured optimized result
 * (299) and the smaller of the two regression totals (339), so either
 * regression alone — not just both together — fails this assertion.
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

    // Measured optimized result through this exact Livewire flow: 299
    // (~14.95/row). 310 is deliberately tight enough to catch either half
    // of the M6 optimization being reverted alone (~339 and ~356 — see
    // this test's own docblock above for the exact derivation), not just
    // a full reversion back toward the pre-optimization ~19-20/row
    // (~380-400 total) baseline.
    expect($queryCount)->toBeLessThan(310);
});
