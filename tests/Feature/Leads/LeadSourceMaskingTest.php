<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

test('editing a facebook source without changing the masked verify token preserves the real stored value', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => [
            'page_id' => '123',
            'page_access_token' => 'real-page-access-token',
            'verify_token' => 'real-verify-token',
        ],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('edit', $source->id)
        ->assertSet('config.verify_token', '••••••••')
        ->call('save');

    $source->refresh();

    expect($source->config)->toMatchArray([
        'page_id' => '123',
        'page_access_token' => 'real-page-access-token',
        'verify_token' => 'real-verify-token',
    ]);
});

test('editing a facebook source with a new verify token updates the stored value', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => [
            'page_id' => '123',
            'page_access_token' => 'real-page-access-token',
            'verify_token' => 'old-verify-token',
        ],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('edit', $source->id)
        ->set('config.verify_token', 'brand-new-verify-token')
        ->call('save');

    $source->refresh();

    expect($source->config['verify_token'])->toBe('brand-new-verify-token');
});
