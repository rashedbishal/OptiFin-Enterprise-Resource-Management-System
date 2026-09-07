<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

test('the lead sources table shows a website form source\'s api key', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'name' => 'Contact Page',
        'config' => ['api_key' => 'secret-api-key-value', 'allowed_domains' => []],
    ]);

    $html = Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('completeInitialLoad')
        ->html();

    expect($html)->toContain('secret-api-key-value');
    expect($html)->toContain('API Key:');
});

test('the lead sources table does not show an api key row for a non-website-form source', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'x', 'verify_token' => 'y'],
    ]);

    $html = Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('completeInitialLoad')
        ->html();

    expect($html)->not->toContain('API Key:');
});
