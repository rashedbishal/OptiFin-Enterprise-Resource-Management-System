<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * A `select`-type config field (e.g. Instagram's "Login Via") renders via
 * <x-dropdown-select>, which binds to Livewire through Alpine's
 * $wire.entangle() rather than a native wire:model. Unlike a native
 * wire:model, entangle() only works if the target property already exists
 * on the component when the field renders — otherwise it throws "Livewire
 * property cannot be found on component" and the selection never reaches
 * the server, silently leaving any field with a matching `depends_on`
 * permanently disabled. This can't be caught by directly calling ->set()
 * on the Livewire test helper (that bypasses entangle() entirely, which is
 * exactly why the LinkedIn lead_type tests never caught it) — the
 * regression to guard is that every config key exists the moment the type
 * is selected, before any dropdown-select for that type ever renders.
 */
test('selecting a type seeds every one of its config keys up front', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->set('type', LeadSourceType::Instagram->value)
        ->assertSet('config', [
            'auth_mode' => null,
            'instagram_account_id' => null,
            'access_token' => null,
            'verify_token' => null,
            'app_secret' => null,
        ]);
});

test('switching types replaces the config keys with the new type\'s own set', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->set('type', LeadSourceType::Instagram->value)
        ->set('config.auth_mode', 'instagram')
        ->set('type', LeadSourceType::Whatsapp->value)
        ->assertSet('config', [
            'phone_number_id' => null,
            'access_token' => null,
            'verify_token' => null,
            'app_secret' => null,
            'whatsapp_business_account_id' => null,
        ]);
});

test('editing an existing source seeds any config keys missing from its stored config', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->for($tenant)->create([
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'facebook'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('edit', $source->id)
        ->assertSet('config.auth_mode', 'facebook')
        ->assertSet('config.instagram_account_id', null)
        ->assertSet('config.access_token', null);
});
