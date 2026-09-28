<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\LeadImports\Index as LeadImportsIndex;
use App\Livewire\Tenant\Leads\Index as LeadsIndex;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/*
 * Batch 4 — LeadSource singleton race. Manual and CsvImport are the only
 * two LeadSourceTypes confirmed singleton-per-tenant (see
 * LeadSource::SINGLETON_TYPES's own docblock): neither is independently
 * creatable through the Platform Admin panel, so CreateManualLead's and
 * LeadImports\Index::startImport()'s own lazy "does one exist yet"
 * check-then-create is each type's ONLY writer. Two concurrent first-ever
 * requests for a tenant (its first manual lead, its first CSV import) could
 * previously both pass that check before either committed, producing two
 * rows and silently fragmenting that tenant's attribution/reporting for
 * that type forever.
 *
 * These tests force the real concurrent interleaving via a DB::listen()
 * hook on the fix's own fast-path SELECT (`where(['tenant_id' =>
 * ..., 'type' => ...])->first()`): the moment that query completes and
 * returns nothing, a competing row is inserted directly — simulating a
 * second request's create() actually committing in that exact window,
 * before this request's own createOrFirst() attempt runs. Deliberately NOT
 * a `LeadSource::creating` listener nested inside createOrFirst()'s own
 * create() call: that would insert the "winner" INSIDE the SAVEPOINT
 * createOrFirst()'s withSavepointIfNeeded() opens for the losing attempt,
 * so the loser's own rollback-to-savepoint would silently undo the
 * "winner" too — a same-connection test artifact that can't happen for two
 * genuinely separate concurrent connections/transactions, where the
 * winner's row is either not yet visible or already durably committed
 * before the loser's savepoint even opens. Racing on the SELECT instead of
 * inside the INSERT's own event keeps the competing insert outside that
 * savepoint's scope, matching real cross-connection concurrency.
 */

beforeEach(fn () => Storage::fake('local'));

/**
 * Registers a one-shot query listener that inserts a competing LeadSource
 * for the same tenant+type the moment the fix's own fast-path existence
 * check (a `select ... from lead_sources where tenant_id = ? and type = ?`)
 * completes and finds nothing — see this file's own docblock for why the
 * race is injected here rather than inside the create() call itself.
 */
function raceOnFirstLeadSourceSelect(string $tenantId, LeadSourceType $type, string $winnerName): void
{
    $raced = false;

    DB::listen(function ($query) use (&$raced, $tenantId, $type, $winnerName) {
        if ($raced) {
            return;
        }

        if (! str_starts_with(ltrim($query->sql), 'select') || ! str_contains($query->sql, 'lead_sources')) {
            return;
        }

        if (! in_array($tenantId, $query->bindings, true) || ! in_array($type->value, $query->bindings, true)) {
            return;
        }

        $raced = true;

        LeadSource::query()->create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'name' => $winnerName,
            'is_active' => true,
        ]);
    });
}

test('CreateManualLead losing the Manual LeadSource create race recovers the winner instead of throwing', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    raceOnFirstLeadSourceSelect($tenant->id, LeadSourceType::Manual, 'Concurrent winner');

    Livewire::actingAs($clientAdmin)
        ->test(LeadsIndex::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Race Test Lead')
        ->set('leadPhone', '+8801700000001')
        ->call('saveLead')
        ->assertHasNoErrors();

    $sources = LeadSource::where('tenant_id', $tenant->id)->where('type', LeadSourceType::Manual)->get();
    expect($sources)->toHaveCount(1);
    expect($sources->sole()->name)->toBe('Concurrent winner');

    $lead = Lead::where('tenant_id', $tenant->id)->where('name', 'Race Test Lead')->firstOrFail();
    expect($lead->source->name)->toBe('Concurrent winner');
});

test('LeadImports startImport losing the CsvImport LeadSource create race recovers the winner instead of throwing', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Excel::fake();

    raceOnFirstLeadSourceSelect($tenant->id, LeadSourceType::CsvImport, 'Concurrent winner');

    $csv = "name,email\nJohn Doe,john@example.com\n";

    Livewire::actingAs($clientAdmin)
        ->test(LeadImportsIndex::class)
        ->set('csvFile', UploadedFile::fake()->createWithContent('leads.csv', $csv))
        ->call('import')
        ->assertHasNoErrors();

    $sources = LeadSource::where('tenant_id', $tenant->id)->where('type', LeadSourceType::CsvImport)->get();
    expect($sources)->toHaveCount(1);
    expect($sources->sole()->name)->toBe('Concurrent winner');

    $import = LeadImport::where('tenant_id', $tenant->id)->firstOrFail();
    expect($import->source->name)->toBe('Concurrent winner');
});

test('a tenant can have multiple non-singleton LeadSources of the same type with no race protection needed', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $first = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $second = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);

    expect($first->singleton_type)->toBeNull();
    expect($second->singleton_type)->toBeNull();
    expect($first->id)->not->toBe($second->id);
    expect(LeadSource::where('tenant_id', $tenant->id)->where('type', LeadSourceType::Whatsapp)->count())->toBe(2);
});

test('WebsiteForm LeadSources are excluded from the singleton constraint and remain independently creatable', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $first = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $second = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);

    expect($first->singleton_type)->toBeNull();
    expect($second->singleton_type)->toBeNull();
    expect(LeadSource::where('tenant_id', $tenant->id)->where('type', LeadSourceType::WebsiteForm)->count())->toBe(2);
});
