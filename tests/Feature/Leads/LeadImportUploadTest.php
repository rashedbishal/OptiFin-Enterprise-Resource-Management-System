<?php

use App\Enums\LeadImportRowStatus;
use App\Enums\LeadSourceType;
use App\Imports\LeadCsvImport;
use App\Livewire\Tenant\LeadImports\Index;
use App\Livewire\Tenant\LeadImports\Show;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\LeadSource;
use Illuminate\Http\UploadedFile;
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
