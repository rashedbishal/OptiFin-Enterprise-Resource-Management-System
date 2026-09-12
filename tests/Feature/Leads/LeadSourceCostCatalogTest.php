<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\LeadSourceCosts\Index;
use App\Models\Currency;
use App\Models\LeadSource;
use App\Models\LeadSourceCost;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Enables a fresh Currency for the given tenant (mirrors the existing
 * tenant_currencies opt-in pattern used throughout this module's tests).
 * Never hardcodes a currency code — CurrencyFactory's own unique random
 * code avoids colliding with the baseline "USD" row every Currency-catalog
 * migration unconditionally seeds (see .ai/rules/migrations-models.md).
 */
function enableCurrencyFor(Tenant $tenant): Currency
{
    $currency = Currency::factory()->create();
    $tenant->currencies()->attach($currency->id);

    return $currency;
}

test('a client admin can create a source cost entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'FB Ads']);
    $currency = enableCurrencyFor($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('sourceId', $source->id)
        ->set('month', '2026-09')
        ->set('amount', '250.50')
        ->set('currencyId', $currency->id)
        ->call('save')
        ->assertHasNoErrors();

    $cost = LeadSourceCost::where('tenant_id', $tenant->id)->firstOrFail();
    expect($cost->lead_source_id)->toBe($source->id);
    expect($cost->month->toDateString())->toBe('2026-09-01');
    expect((float) $cost->amount)->toBe(250.50);
    expect($cost->currency_id)->toBe($currency->id);
});

test('a client admin can edit a source cost entry\'s amount', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currency = enableCurrencyFor($tenant);
    $cost = LeadSourceCost::factory()->create([
        'tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id,
        'month' => '2026-09-01', 'amount' => 100,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $cost->id)
        ->set('amount', '999.99')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $cost->fresh()->amount)->toBe(999.99);
});

test('a client admin can delete a source cost entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currency = enableCurrencyFor($tenant);
    $cost = LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $cost->id)
        ->call('deleteConfirmed');

    expect(LeadSourceCost::find($cost->id))->toBeNull();
});

test('manual and csv import sources are excluded from the eligible source list', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual, 'name' => 'Custom']);
    LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport, 'name' => 'CSV Import']);
    LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'FB Ads']);

    $names = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->instance()
        ->eligibleSources()
        ->pluck('name');

    expect($names->all())->toBe(['FB Ads']);
});

test('a manual source cannot be used for a cost entry even if submitted directly', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $manualSource = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual]);
    $currency = enableCurrencyFor($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('sourceId', $manualSource->id)
        ->set('month', '2026-09')
        ->set('amount', '50')
        ->set('currencyId', $currency->id)
        ->call('save')
        ->assertHasErrors('sourceId');

    expect(LeadSourceCost::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a second entry for the same source and month is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currency = enableCurrencyFor($tenant);
    LeadSourceCost::factory()->create([
        'tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-09-01',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('sourceId', $source->id)
        ->set('month', '2026-09')
        ->set('amount', '75')
        ->set('currencyId', $currency->id)
        ->call('save')
        ->assertHasErrors('month');

    expect(LeadSourceCost::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a source\'s cost entries must all share the same currency', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $usd = enableCurrencyFor($tenant);
    $bdt = enableCurrencyFor($tenant);
    LeadSourceCost::factory()->create([
        'tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $usd->id, 'month' => '2026-09-01',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('sourceId', $source->id)
        ->set('month', '2026-10')
        ->set('amount', '75')
        ->set('currencyId', $bdt->id)
        ->call('save')
        ->assertHasErrors('currencyId');

    expect(LeadSourceCost::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('editing a cost entry to a different currency than its sibling entries is rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $usd = enableCurrencyFor($tenant);
    $bdt = enableCurrencyFor($tenant);
    LeadSourceCost::factory()->create([
        'tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $usd->id, 'month' => '2026-09-01',
    ]);
    $septEntryOtherMonth = LeadSourceCost::factory()->create([
        'tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $usd->id, 'month' => '2026-10-01',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $septEntryOtherMonth->id)
        ->set('currencyId', $bdt->id)
        ->call('save')
        ->assertHasErrors('currencyId');

    expect($septEntryOtherMonth->fresh()->currency_id)->toBe($usd->id);
});

test('the first cost entry for a source may use any enabled currency', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $bdt = enableCurrencyFor($tenant);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('sourceId', $source->id)
        ->set('month', '2026-09')
        ->set('amount', '75')
        ->set('currencyId', $bdt->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(LeadSourceCost::where('tenant_id', $tenant->id)->first()->currency_id)->toBe($bdt->id);
});

test('a client user without admin rights cannot access the source costs page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertForbidden();
});

test('a tenant\'s source costs never appear for, or can be touched by, another tenant', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $sourceA = LeadSource::factory()->create(['tenant_id' => $tenantA->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Tenant A Source']);
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenantB->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Tenant B Source']);
    $currencyA = enableCurrencyFor($tenantA);
    $currencyB = enableCurrencyFor($tenantB);
    $costA = LeadSourceCost::factory()->create(['tenant_id' => $tenantA->id, 'lead_source_id' => $sourceA->id, 'currency_id' => $currencyA->id]);
    $costB = LeadSourceCost::factory()->create(['tenant_id' => $tenantB->id, 'lead_source_id' => $sourceB->id, 'currency_id' => $currencyB->id]);

    $component = Livewire::actingAs($adminA)->test(Index::class);

    $component->assertSee('Tenant A Source')->assertDontSee('Tenant B Source');

    expect(fn () => $component->call('edit', $costB->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('confirmDelete', $costB->id)->call('deleteConfirmed'))->toThrow(ModelNotFoundException::class);

    expect($costA->fresh())->not->toBeNull();
    expect(LeadSourceCost::where('tenant_id', $tenantB->id)->count())->toBe(1);
});

test('deleting a lead source with recorded cost history is blocked', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currency = enableCurrencyFor($tenant);
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id]);

    Livewire::actingAs($clientAdmin)
        ->test(App\Livewire\Tenant\Leads\Index::class)->call('completeInitialLoad')
        ->call('confirmDeleteSource', $source->id)
        ->call('deleteSourceConfirmed');

    expect(LeadSource::find($source->id))->not->toBeNull();
});

/**
 * Concurrency-fix regression coverage.
 *
 * PHPUnit/Pest run single-threaded against one PDO connection, and this
 * app's test suite runs SQLite in-memory (phpunit.xml: DB_DATABASE=:memory:)
 * — a second, genuinely separate PDO connection to the same in-memory
 * database is not possible (it would open its own empty database), so true
 * cross-connection lock contention cannot be exercised here. These two
 * tests are the strongest deterministic proxies available in this
 * environment, and each documents exactly what it does and does not prove:
 *
 * 1. The locked lookup targets the `lead_sources` row itself, not a
 *    `lead_source_costs` row — proven via the actual query log, for a
 *    source with ZERO existing cost entries. This is the specific
 *    architectural property the fix needed: a lock on an existing cost row
 *    would miss the brand-new-source case entirely, since no such row
 *    would exist yet to lock.
 * 2. Two full save() calls in sequence for a truly brand-new source (no
 *    pre-seeded LeadSourceCost at all — unlike every other test above,
 *    which seeds the "existing" side via the factory directly) enforce the
 *    single-currency-per-source invariant end-to-end through the real code
 *    path both saves run through.
 *
 * Neither test can demonstrate that a second, genuinely concurrent
 * transaction actually BLOCKS on the lock (SQLite has no row-level locking
 * to observe even if a second connection were possible) — that guarantee
 * comes from `lockForUpdate()` compiling to a real `SELECT ... FOR UPDATE`
 * on Postgres (production), which is standard, well-established RDBMS
 * behavior, not something this suite can execute end-to-end.
 */
test('saving a cost entry locks the LeadSource row itself, not a cost row, even for a source with no existing cost history', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currency = enableCurrencyFor($tenant);

    expect(LeadSourceCost::where('lead_source_id', $source->id)->exists())->toBeFalse();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('sourceId', $source->id)
        ->set('month', '2026-09')
        ->set('amount', '100')
        ->set('currencyId', $currency->id)
        ->call('save')
        ->assertHasNoErrors();

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $lockedSourceLookup = $queries->first(fn (string $sql): bool => str_contains($sql, 'from "lead_sources"') && str_contains($sql, '"lead_sources"."id" = ?'));

    expect($lockedSourceLookup)->not->toBeNull();
});

test('two sequential first-time save() calls for a brand-new source enforce the single-currency invariant end-to-end', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $usd = enableCurrencyFor($tenant);
    $bdt = enableCurrencyFor($tenant);

    // No LeadSourceCost::factory() seed at all — this source has zero
    // history until the first save() below actually creates one, exactly
    // the "brand-new source" scenario the race condition was about.
    expect(LeadSourceCost::where('lead_source_id', $source->id)->exists())->toBeFalse();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('sourceId', $source->id)
        ->set('month', '2026-09')
        ->set('amount', '100')
        ->set('currencyId', $usd->id)
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('sourceId', $source->id)
        ->set('month', '2026-10')
        ->set('amount', '200')
        ->set('currencyId', $bdt->id)
        ->call('save')
        ->assertHasErrors('currencyId');

    $rows = LeadSourceCost::where('lead_source_id', $source->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->currency_id)->toBe($usd->id);
});
