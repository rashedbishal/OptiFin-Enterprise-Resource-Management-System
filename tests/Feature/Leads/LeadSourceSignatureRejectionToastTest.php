<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

test('opening the lead sources page shows a toast for a recorded signature rejection, then clears it', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'secret'],
        'last_signature_rejection_at' => now()->subMinutes(5),
        'last_signature_rejection_reason' => 'Signature mismatch on the facebook_messenger/facebook_lead_ads webhook — the App Secret doesn\'t match what Meta signed this request with.',
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger'
            && str_contains($params['slots']['text'], 'Signature mismatch'));

    $source->refresh();
    expect($source->last_signature_rejection_at)->toBeNull();
    expect($source->last_signature_rejection_reason)->toBeNull();
});

test('opening the lead sources page a second time does not show the same rejection again', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'x', 'verify_token' => 'y', 'app_secret' => 'secret'],
        'last_signature_rejection_at' => now()->subMinutes(5),
        'last_signature_rejection_reason' => 'Signature mismatch on the facebook_messenger webhook.',
    ]);

    Livewire::actingAs($motherAdmin, 'platform')->test(LeadSources::class, ['tenant' => $tenant]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->assertNotDispatched('toast-show');
});

test('opening the lead sources page with no rejections shows no toast', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'x', 'verify_token' => 'y'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->assertNotDispatched('toast-show');
});
