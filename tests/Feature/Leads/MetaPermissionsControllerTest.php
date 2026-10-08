<?php

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\Http;

test('a platform admin sees granted vs required scopes with the token masked', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'EAAoXmSomeVeryLongRealLookingToken1234567890', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/debug_token*' => Http::response([
        'data' => [
            'app_id' => '2840700582974064',
            'is_valid' => true,
            'expires_at' => 0,
            'scopes' => ['pages_messaging', 'leads_retrieval'],
        ],
    ])]);

    $response = $this->actingAs($motherAdmin, 'platform')
        ->getJson(route('admin.clients.lead-sources.meta-permissions', [$tenant, $source]))
        ->assertOk();

    $response->assertJson([
        'lead_source_id' => $source->id,
        'app_id' => '2840700582974064',
        'is_valid' => true,
        'granted_scopes' => ['pages_messaging', 'leads_retrieval'],
        'required_scopes' => [
            'pages_messaging' => true,
            'instagram_manage_messages' => false,
            'leads_retrieval' => true,
            'pages_manage_metadata' => false,
        ],
    ]);

    $token = $response->json('token');
    expect($token)->not->toContain('EAAoXmSomeVeryLongRealLookingToken1234567890');
    expect($token)->toEndWith('1234567890');
});

test('a whatsapp source is checked against the whatsapp_business_messaging scope', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '1', 'access_token' => 'EAAoXmSomeVeryLongRealLookingToken1234567890', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/debug_token*' => Http::response([
        'data' => ['app_id' => '1', 'is_valid' => true, 'expires_at' => 0, 'scopes' => ['whatsapp_business_messaging']],
    ])]);

    $this->actingAs($motherAdmin, 'platform')
        ->getJson(route('admin.clients.lead-sources.meta-permissions', [$tenant, $source]))
        ->assertOk()
        ->assertJson(['required_scopes' => ['whatsapp_business_messaging' => true, 'pages_messaging' => false]]);
});

test('a messenger source is checked against the page_utility_messaging scope', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'EAAoXmSomeVeryLongRealLookingToken1234567890', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/debug_token*' => Http::response([
        'data' => ['app_id' => '1', 'is_valid' => true, 'expires_at' => 0, 'scopes' => ['pages_messaging']],
    ])]);

    $this->actingAs($motherAdmin, 'platform')
        ->getJson(route('admin.clients.lead-sources.meta-permissions', [$tenant, $source]))
        ->assertOk()
        ->assertJson(['required_scopes' => ['pages_messaging' => true, 'page_utility_messaging' => false]]);
});

test('a lead source with no access token configured returns a clear error instead of calling the graph api', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'verify_token' => 'secret'],
    ]);

    Http::fake();

    $this->actingAs($motherAdmin, 'platform')
        ->getJson(route('admin.clients.lead-sources.meta-permissions', [$tenant, $source]))
        ->assertStatus(422);

    Http::assertNothingSent();
});

test('an unauthenticated request is redirected to the platform login', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'token', 'verify_token' => 'secret'],
    ]);

    $this->get(route('admin.clients.lead-sources.meta-permissions', [$tenant, $source]))
        ->assertRedirect(route('admin.login'));
});

test('a lead source belonging to a different tenant returns not found', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $sourceB = LeadSource::factory()->create([
        'tenant_id' => $tenantB->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'token', 'verify_token' => 'secret'],
    ]);

    $this->actingAs($motherAdmin, 'platform')
        ->getJson(route('admin.clients.lead-sources.meta-permissions', [$tenantA, $sourceB]))
        ->assertNotFound();
});
