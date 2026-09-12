<?php

use App\Enums\LeadImportRowStatus;
use App\Enums\LeadImportStatus;
use App\Livewire\Tenant\LeadImports\Index;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use Illuminate\Http\UploadedFile;
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
