<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\LeadReports\Index;
use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadSourceCost;
use App\Models\Tenant;
use App\Services\LeadReportingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function enableCurrency(Tenant $tenant): Currency
{
    $currency = Currency::factory()->create();
    $tenant->currencies()->attach($currency->id);

    return $currency;
}

test('cost per lead is calculated correctly for a single fully-covered month', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currency = enableCurrency($tenant);
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-09-01', 'amount' => 300]);

    Lead::factory()->count(5)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-09-15')]);
    Lead::factory()->count(2)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-08-15')]);

    $summary = app(LeadReportingService::class)->sourceCostSummary($tenant, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'));

    expect($summary['rows'])->toHaveCount(1);
    $row = $summary['rows']->first();
    expect($row['source']->id)->toBe($source->id);
    expect($row['total_cost'])->toBe('300.00');
    expect($row['lead_count'])->toBe(5);
    expect($row['cost_per_lead'])->toBe(60.0);
    expect($summary['excluded_months'])->toBe([]);
});

test('multiple fully-covered months are summed for the same source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currency = enableCurrency($tenant);
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-08-01', 'amount' => 200]);
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-09-01', 'amount' => 300]);

    Lead::factory()->count(3)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-08-10')]);
    Lead::factory()->count(5)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-09-10')]);

    $summary = app(LeadReportingService::class)->sourceCostSummary($tenant, Carbon::parse('2026-08-01'), Carbon::parse('2026-10-01'));

    $row = $summary['rows']->first();
    expect($row['total_cost'])->toBe('500.00');
    expect($row['lead_count'])->toBe(8);
    expect($row['cost_per_lead'])->toBe(62.5);
});

test('a fully-covered month with no recorded cost entry is excluded from the calculation, not treated as zero spend', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currency = enableCurrency($tenant);
    // No entry for August — only September is tracked.
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-09-01', 'amount' => 300]);

    Lead::factory()->count(3)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-08-10')]);
    Lead::factory()->count(5)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-09-10')]);

    $summary = app(LeadReportingService::class)->sourceCostSummary($tenant, Carbon::parse('2026-08-01'), Carbon::parse('2026-10-01'));

    $row = $summary['rows']->first();
    expect($row['total_cost'])->toBe('300.00');
    expect($row['lead_count'])->toBe(5);
    // August was fully covered by the range but had no entry — it must not
    // appear in excluded_months (that's reserved for range-boundary partial
    // months) and must not silently count as $0 spend.
    expect($summary['excluded_months'])->toBe([]);
});

test('a calendar month only partially covered by the selected range is excluded and named in the disclaimer', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currency = enableCurrency($tenant);
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-09-01', 'amount' => 100]);
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-10-01', 'amount' => 300]);

    Lead::factory()->count(2)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-09-20')]);
    Lead::factory()->count(5)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-10-10')]);

    // Range starts mid-September (partial) through end of October (full).
    $summary = app(LeadReportingService::class)->sourceCostSummary($tenant, Carbon::parse('2026-09-15'), Carbon::parse('2026-11-01'));

    $row = $summary['rows']->first();
    expect($row['total_cost'])->toBe('300.00');
    expect($row['lead_count'])->toBe(5);
    expect($summary['excluded_months'])->toBe(['September 2026']);
});

test('a range with zero fully-covered calendar months returns no rows', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currency = enableCurrency($tenant);
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-09-01', 'amount' => 300]);

    $summary = app(LeadReportingService::class)->sourceCostSummary($tenant, Carbon::parse('2026-09-10'), Carbon::parse('2026-09-20'));

    expect($summary['rows'])->toBeEmpty();
    expect($summary['excluded_months'])->toBe(['September 2026']);
});

test('two sources tracked in different currencies are shown unblended with correct per-currency totals', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $sourceA = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'Source A']);
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger, 'name' => 'Source B']);
    $usd = enableCurrency($tenant);
    $bdt = enableCurrency($tenant);
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $sourceA->id, 'currency_id' => $usd->id, 'month' => '2026-09-01', 'amount' => 300]);
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $sourceB->id, 'currency_id' => $bdt->id, 'month' => '2026-09-01', 'amount' => 1000]);

    $summary = app(LeadReportingService::class)->sourceCostSummary($tenant, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'));

    expect($summary['rows'])->toHaveCount(2);
    expect($summary['totals_by_currency'])->toHaveCount(2);

    $totalsByCurrencyCode = $summary['totals_by_currency']->mapWithKeys(fn ($t) => [$t['currency']->code => $t['total']]);
    expect($totalsByCurrencyCode[$usd->code])->toBe('300.00');
    expect($totalsByCurrencyCode[$bdt->code])->toBe('1000.00');
});

test('another tenant\'s source costs never leak into the summary', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $sourceA = LeadSource::factory()->create(['tenant_id' => $tenantA->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenantB->id, 'type' => LeadSourceType::FacebookLeadAds]);
    $currencyA = enableCurrency($tenantA);
    $currencyB = enableCurrency($tenantB);
    LeadSourceCost::factory()->create(['tenant_id' => $tenantA->id, 'lead_source_id' => $sourceA->id, 'currency_id' => $currencyA->id, 'month' => '2026-09-01', 'amount' => 300]);
    LeadSourceCost::factory()->create(['tenant_id' => $tenantB->id, 'lead_source_id' => $sourceB->id, 'currency_id' => $currencyB->id, 'month' => '2026-09-01', 'amount' => 999]);

    $summary = app(LeadReportingService::class)->sourceCostSummary($tenantA, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'));

    expect($summary['rows'])->toHaveCount(1);
    expect($summary['rows']->first()['source']->id)->toBe($sourceA->id);
});

test('rendering the cost per lead card does not N+1 per-source lookups', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $currency = enableCurrency($tenant);

    foreach (range(1, 5) as $i) {
        $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => "Source {$i}"]);
        LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-09-01', 'amount' => 100]);
        Lead::factory()->count(2)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-09-15')]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $summary = app(LeadReportingService::class)->sourceCostSummary($tenant, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'));
    $summary['rows']->each(fn (array $row) => [$row['source']->name, $row['currency']->code]);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($summary['rows'])->toHaveCount(5);
    // A per-source count() would have cost 1 extra query per source (8
    // total for 5 sources — the exact count the un-fixed N+1 pattern
    // produced: allCosts + 2 eager loads + 5 per-source counts). The single
    // grouped query fix costs a fixed 4 (allCosts + 2 eager loads + 1
    // grouped lead-count query), independent of source count — see the
    // scaling test below for the stronger, count-independent proof.
    expect($queryCount)->toBeLessThanOrEqual(5);
});

test('sourceCostSummary() issues the same number of queries regardless of how many sources have cost data', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $currency = enableCurrency($tenant);

    $makeSources = function (int $count) use ($tenant, $currency): void {
        foreach (range(1, $count) as $i) {
            $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => "Src {$i}-{$count}"]);
            LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-09-01', 'amount' => 100]);
            Lead::factory()->count(2)->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-09-15')]);
        }
    };

    $countQueriesFor = function () use ($tenant): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(LeadReportingService::class)->sourceCostSummary($tenant, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $makeSources(2);
    $queriesForTwo = $countQueriesFor();

    $makeSources(8);
    $queriesForTen = $countQueriesFor();

    // If the per-source count() query were still there, adding 8 more
    // sources would add 8 more queries. A single grouped query costs the
    // exact same number of round trips no matter how many sources or
    // cost-rows it covers.
    expect($queriesForTen)->toBe($queriesForTwo);
});

test('the reports page renders the cost per lead card, disclaimer, and source-costs link', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds, 'name' => 'FB Ads']);
    $currency = enableCurrency($tenant);
    // September is only partially covered by the selected range below and
    // must be excluded/disclaimed; October is fully covered and must be
    // the one actually rendered in the table.
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-09-01', 'amount' => 100]);
    LeadSourceCost::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'currency_id' => $currency->id, 'month' => '2026-10-01', 'amount' => 300]);

    Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'created_at' => Carbon::parse('2026-10-15')]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('fromDate', '2026-09-15')
        ->set('toDate', '2026-10-31')
        ->assertSee('Cost per Lead')
        ->assertSee(route('tenant-leads.source-costs'), false)
        ->assertSee('September 2026')
        ->assertSee('FB Ads');
});
