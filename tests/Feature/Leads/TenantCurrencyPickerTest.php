<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\Currency;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('a freshly created tenant has exactly one currency enabled', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    $component = Livewire::actingAs($motherAdmin, 'platform')->test(LeadSources::class, ['tenant' => $tenant]);

    expect($component->get('selectedCurrencies'))->toHaveCount(1);
});

test('clicking an unselected currency enables it for the tenant', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $eur = Currency::factory()->create(['code' => 'XEU', 'name' => 'Test Euro']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('toggleCurrency', $eur->id);

    expect($tenant->fresh()->currencies->pluck('code'))->toContain('XEU');
});

test('clicking an already-enabled currency disables it for the tenant', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $eur = Currency::factory()->create(['code' => 'XEU', 'name' => 'Test Euro']);
    $tenant->currencies()->attach($eur->id);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('toggleCurrency', $eur->id);

    expect($tenant->fresh()->currencies->pluck('code'))->not->toContain('XEU');
});

test('the last remaining currency cannot be disabled', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $onlyCurrency = $tenant->currencies()->first();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('toggleCurrency', $onlyCurrency->id)
        ->assertDispatched('toast-show');

    expect($tenant->fresh()->currencies)->toHaveCount(1);
    expect($tenant->fresh()->currencies->first()->id)->toBe($onlyCurrency->id);
});

test('two concurrent removals of a tenant\'s last two currencies never leave it with zero enabled', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $currencyA = $tenant->currencies()->first();
    $currencyB = Currency::factory()->create(['code' => 'XCB', 'name' => 'Test Currency B']);
    $tenant->currencies()->attach($currencyB->id);

    expect($tenant->fresh()->currencies)->toHaveCount(2);

    // Mounted separately from the call under test — Livewire's own
    // mount-time reads (allCurrencies()/selectedCurrencies()) also touch
    // the "currencies" table, so capturing from mount onward would
    // intercept the wrong query and silently fail to reproduce the race.
    $component = Livewire::actingAs($motherAdmin, 'platform')->test(LeadSources::class, ['tenant' => $tenant]);

    // Deterministic failure injection, the same technique this module's
    // own prior TOCTOU closures use: intercept toggleCurrency()'s own
    // first currency-ID read (its unlocked branch-decision read — the
    // exact query the original, unfixed method relied on for its entire
    // count check) and, from that interception, simulate a concurrent
    // process independently committing its own removal of the OTHER
    // currency in that gap — a raw, unguarded detach(), deliberately
    // bypassing the method entirely, exactly as a second real concurrent
    // request would. Against unfixed code, this call's own stale
    // $currentIds (captured before the concurrent detach) still shows
    // count() == 2, so it proceeds to remove A too — zero remain. Against
    // the fix, the removal branch's locked re-read correctly observes
    // only A left and refuses via the existing "at least one" toast —
    // A survives, B (removed independently, outside any protocol) does
    // not.
    $intercepted = false;

    DB::listen(function ($query) use (&$intercepted, $tenant, $currencyB): void {
        if ($intercepted || ! str_starts_with($query->sql, 'select "currencies"."id"')) {
            return;
        }

        $intercepted = true;
        $tenant->currencies()->detach($currencyB->id);
    });

    $component->call('toggleCurrency', $currencyA->id)
        ->assertDispatched('toast-show');

    $remaining = $tenant->fresh()->currencies;

    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->id)->toBe($currencyA->id);
});

test('a tenant with zero currencies cannot open the new lead source modal', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $tenant->currencies()->detach();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('create')
        ->assertDispatched('toast-show')
        ->assertSet('showModal', false);
});

test('a tenant with zero currencies cannot save a new lead source even if the modal was already open', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    $component = Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->set('type', LeadSourceType::WebsiteForm->value)
        ->set('name', 'Website');

    // Simulate the tenant's last currency being removed elsewhere after the
    // modal was already opened, before this save() call reaches the server.
    $tenant->currencies()->detach();

    $component->call('save')->assertDispatched('toast-show');

    expect($tenant->leadSources()->count())->toBe(0);
});

test('a tenant with at least one currency can create a new lead source normally', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('create')
        ->assertSet('showModal', true)
        ->set('type', LeadSourceType::WebsiteForm->value)
        ->set('name', 'Website')
        ->call('save')
        ->assertHasNoErrors();

    expect($tenant->leadSources()->where('name', 'Website')->exists())->toBeTrue();
});

test('editing an existing lead source is unaffected by the tenant having zero currencies', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = $tenant->leadSources()->create([
        'type' => LeadSourceType::WebsiteForm,
        'name' => 'Existing Source',
        'config' => ['api_key' => 'test-key'],
        'is_active' => true,
    ]);
    $tenant->currencies()->detach();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('edit', $source->id)
        ->set('name', 'Renamed Source')
        ->call('save')
        ->assertHasNoErrors();

    expect($source->fresh()->name)->toBe('Renamed Source');
});
