<?php

use App\Enums\LeadImportRowStatus;
use App\Enums\LeadImportStatus;
use App\Enums\LeadSourceType;
use App\Imports\LeadCsvImport;
use App\Livewire\Tenant\LeadImports\Index;
use App\Livewire\Tenant\LeadImports\Show;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\LeadSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

// Storage::fake('local') sandboxes every stored/uploaded file to an
// in-memory disk for the whole test run — without it, Index::import()'s
// storeAs() call writes a REAL file into storage/app/private/lead-imports
// on the actual filesystem, since RefreshDatabase only resets the
// database, never the disk. Confirmed via a real dev-environment smoke
// test: 39 orphaned CSVs had accumulated there from prior test runs before
// this was added.
beforeEach(fn () => Storage::fake('local'));

function csvUpload(string $content, string $name = 'leads.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

test('a valid CSV upload creates a LeadImport, queues the import, and redirects to the detail page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "name,email\nJohn Doe,john@example.com\nJane Doe,jane@example.com\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertHasNoErrors();

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();
    expect($import->original_filename)->toBe('leads.csv');
    expect($import->total_rows)->toBe(2);
    expect($import->source->type)->toBe(LeadSourceType::CsvImport);

    Excel::assertQueued($import->file_path, 'local', function (LeadCsvImport $csvImport) {
        return true;
    });
});

test('a CSV missing the required name column is rejected before anything is created', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "email,phone\njohn@example.com,5551234567\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertHasErrors('csvFile');

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a CSV with an unknown column is rejected before anything is created', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "name,favorite_color\nJohn Doe,blue\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertHasErrors('csvFile');

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('header matching is case-insensitive and whitespace-trimmed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = " NAME , Email \nJohn Doe,john@example.com\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertHasNoErrors();

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('an empty CSV (no data rows) is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload("name,email\n"))
        ->call('import')
        ->assertHasErrors('csvFile');

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a CSV with more than 10,000 data rows is rejected before queueing, never truncated', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $lines = ['name'];
    for ($i = 0; $i < 10001; $i++) {
        $lines[] = "Lead {$i}";
    }
    $csv = implode("\n", $lines)."\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertHasErrors('csvFile');

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('exactly 10,000 data rows is accepted', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $lines = ['name'];
    for ($i = 0; $i < 10000; $i++) {
        $lines[] = "Lead {$i}";
    }
    $csv = implode("\n", $lines)."\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertHasNoErrors();

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();
    expect($import->total_rows)->toBe(10000);
});

test('a user without lead-creation permission cannot access the import page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);

    Livewire::actingAs($rep)->test(Index::class)->assertForbidden();
});

test('the import detail page 404s for another tenant\'s import', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $otherTenant->id, 'type' => LeadSourceType::CsvImport]);
    $foreignImport = LeadImport::factory()->create(['tenant_id' => $otherTenant->id, 'source_id' => $source->id]);

    Livewire::actingAs($clientAdmin)->test(Show::class, ['leadImport' => $foreignImport])->assertStatus(404);
});

test('the import detail page shows this tenant\'s own import and its rows', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport]);
    $import = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'imported_by' => $clientAdmin->id]);
    LeadImportRow::factory()->create([
        'lead_import_id' => $import->id,
        'row_number' => 1,
        'status' => LeadImportRowStatus::Imported,
        'raw_data' => ['name' => 'Test Lead'],
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['leadImport' => $import])
        ->assertSee('Test Lead')
        ->assertOk();
});

test('an in-file conflict row is shown on the import detail page through the existing errors column, with no lead link', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $csv = "name,email,phone\nAlpha,alpha@example.com,\nBravo,,5550001234\nCharlie,alpha@example.com,5550001234\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['leadImport' => $import])
        ->assertSee('Charlie')
        ->assertSee('Email matches an earlier row in this CSV file and phone matches a different earlier row — rejected as a duplicate conflict.')
        ->assertDontSee('Duplicate of');
});

/**
 * INC-1 B2 — upload-level file fingerprint. A re-upload of byte-identical
 * content warns before anything is created; the warning is best-effort
 * recognition (no lock, no unique constraint), never a hard block.
 */
test('a first upload stores the SHA-256 of the exact uploaded bytes and imports without any warning', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "name,email\nJohn Doe,john@example.com\n";

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertSet('showDuplicateUploadModal', false);

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();
    expect($import->file_hash)->toBe(hash('sha256', $csv));
});

test('re-uploading identical content warns first, creates nothing, and only imports again after confirmation', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "name,email\nJohn Doe,john@example.com\n";
    LeadImport::factory()->create([
        'tenant_id' => $tenant->id,
        'original_filename' => 'first.csv',
        'file_hash' => hash('sha256', $csv),
    ]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertSet('showDuplicateUploadModal', true)
        ->assertSee('This file was already imported')
        ->assertSee('first.csv was imported on');

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(1);
    expect(Storage::disk('local')->allFiles('lead-imports'))->toBe([]);

    $component->call('confirmDuplicateUpload');

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(2);
    $second = LeadImport::where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
    expect($second->file_hash)->toBe(hash('sha256', $csv));
    Excel::assertQueued($second->file_path, 'local');
});

test('identical bytes under a different filename are recognized', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "name,email\nJohn Doe,john@example.com\n";
    LeadImport::factory()->create(['tenant_id' => $tenant->id, 'file_hash' => hash('sha256', $csv)]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv, 'renamed.csv'))
        ->call('import')
        ->assertSet('showDuplicateUploadModal', true);
});

test('changed content, reordered rows, or different line endings are different files and do not warn', function (string $csv) {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $original = "name,email\nAlice,alice@example.com\nBob,bob@example.com\n";
    LeadImport::factory()->create(['tenant_id' => $tenant->id, 'file_hash' => hash('sha256', $original)]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertSet('showDuplicateUploadModal', false);

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(2);
})->with([
    'changed cell' => "name,email\nAlice,alice@example.com\nRobert,bob@example.com\n",
    'reordered rows' => "name,email\nBob,bob@example.com\nAlice,alice@example.com\n",
    'different line endings' => "name,email\r\nAlice,alice@example.com\r\nBob,bob@example.com\r\n",
]);

test('a prior import by another user of the same tenant triggers the warning and can be confirmed', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "name,email\nJohn Doe,john@example.com\n";
    $otherUser = clientUserWithLeadPermission($tenant, ['create']);
    LeadImport::factory()->create(['tenant_id' => $tenant->id, 'imported_by' => $clientAdmin->id, 'file_hash' => hash('sha256', $csv)]);

    Livewire::actingAs($otherUser)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertSet('showDuplicateUploadModal', true)
        ->call('confirmDuplicateUpload');

    expect(LeadImport::where('tenant_id', $tenant->id)->where('imported_by', $otherUser->id)->count())->toBe(1);
});

test('another tenant importing the same content never triggers a cross-tenant warning', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB, 'clientAdmin' => $adminB] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "name,email\nJohn Doe,john@example.com\n";
    LeadImport::factory()->create(['tenant_id' => $tenantA->id, 'original_filename' => 'secret-a.csv', 'file_hash' => hash('sha256', $csv)]);

    Livewire::actingAs($adminB)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertSet('showDuplicateUploadModal', false)
        ->assertDontSee('secret-a.csv');

    expect(LeadImport::where('tenant_id', $tenantB->id)->count())->toBe(1);
});

test('a prior import in any status triggers the warning and can be confirmed', function (LeadImportStatus $status) {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "name,email\nJohn Doe,john@example.com\n";
    LeadImport::factory()->create(['tenant_id' => $tenant->id, 'status' => $status, 'file_hash' => hash('sha256', $csv)]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertSet('showDuplicateUploadModal', true)
        ->call('confirmDuplicateUpload');

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(2);
})->with(LeadImportStatus::cases());

test('historical imports with a NULL file_hash never trigger a warning', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    LeadImport::factory()->create(['tenant_id' => $tenant->id, 'file_hash' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload("name,email\nJohn Doe,john@example.com\n"))
        ->call('import')
        ->assertHasNoErrors()
        ->assertSet('showDuplicateUploadModal', false);

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('cancelling the warning closes it, keeps the selected file, and imports nothing', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "name,email\nJohn Doe,john@example.com\n";
    LeadImport::factory()->create(['tenant_id' => $tenant->id, 'file_hash' => hash('sha256', $csv)]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertSet('showDuplicateUploadModal', true)
        ->set('showDuplicateUploadModal', false)
        ->assertSet('pendingDuplicateHash', '');

    expect($component->get('csvFile'))->not->toBeNull();
    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(1);

    // A stale confirmation after Cancel is a safe no-op.
    $component->call('confirmDuplicateUpload');
    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('selecting a different file after the warning invalidates the pending confirmation', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $csv = "name,email\nJohn Doe,john@example.com\n";
    LeadImport::factory()->create(['tenant_id' => $tenant->id, 'file_hash' => hash('sha256', $csv)]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload($csv))
        ->call('import')
        ->assertSet('showDuplicateUploadModal', true)
        ->set('csvFile', csvUpload("name,email\nSomeone Else,else@example.com\n", 'other.csv'))
        ->assertSet('showDuplicateUploadModal', false)
        ->assertSet('pendingDuplicateHash', '')
        ->call('confirmDuplicateUpload');

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a confirmation with no pending warning is a safe no-op', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload("name,email\nJohn Doe,john@example.com\n"))
        ->call('confirmDuplicateUpload');

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('the pending hash cannot be set from the client', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('pendingDuplicateHash', str_repeat('a', 64));
})->throws(Exception::class, 'Cannot update locked property');

test('an unreadable file is a normal read error and creates, stores and queues nothing', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload("name,email\nJohn Doe,john@example.com\n"));

    unlink($component->get('csvFile')->getRealPath());

    $component->call('import')->assertHasErrors('csvFile');

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('lead-imports'))->toBe([]);
});

test('a failing prior-import lookup fails closed: nothing is created, stored or queued', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('csvFile', csvUpload("name,email\nJohn Doe,john@example.com\n"));

    DB::listen(function ($query): void {
        if (str_contains($query->sql, '"file_hash"')) {
            throw new RuntimeException('lookup failed');
        }
    });

    expect(fn () => $component->call('import'))->toThrow(RuntimeException::class);

    expect(LeadImport::where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('lead-imports'))->toBe([]);
});
