<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\Currency;
use App\Models\PlatformUser;
use App\Models\Tenant;
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
