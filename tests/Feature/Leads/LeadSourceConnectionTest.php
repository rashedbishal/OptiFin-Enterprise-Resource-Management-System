<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('testing a facebook source with a valid token subscribes the page and reports success', function () {
    Http::fake([
        'graph.facebook.com/123/subscribed_apps*' => Http::response(['success' => true]),
        'graph.facebook.com/123*' => Http::response(['id' => '123', 'name' => 'My Test Page']),
    ]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'valid-token', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success');

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/123/subscribed_apps'
        && $request->method() === 'POST'
        && $request['subscribed_fields'] === 'messages,messaging_postbacks'
        && $request['access_token'] === 'valid-token');
});

test('a facebook source still subscribes and reports success when the cosmetic page-name lookup fails', function () {
    // Reproduces a real production error: a Page Access Token can be
    // perfectly valid for pages_messaging/pages_manage_metadata (everything
    // subscribeFacebookPageToApp() needs) while still lacking the separate,
    // unrelated pages_read_engagement permission (or the Page Public
    // Metadata Access feature) this cosmetic GET /{page-id}?fields=id,name
    // lookup needs — Meta's own (#100) error. The lookup failing must never
    // block the actually-important subscribe call.
    Http::fake([
        'graph.facebook.com/123/subscribed_apps*' => Http::response(['success' => true]),
        'graph.facebook.com/123*' => Http::response(['error' => [
            'message' => "(#100) Object does not exist, cannot be loaded due to missing permission or reviewable feature, or does not support this operation. This endpoint requires the 'pages_read_engagement' permission or the 'Page Public Content Access' feature or the 'Page Public Metadata Access' feature.",
        ]], 400),
    ]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'valid-messaging-token', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success'
            && str_contains($params['slots']['text'], '123'));

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/123/subscribed_apps'
        && $request->method() === 'POST'
        && $request['subscribed_fields'] === 'messages,messaging_postbacks'
        && $request['access_token'] === 'valid-messaging-token');
});

test('a facebook lead ads source still subscribes and reports success when the cosmetic page-name lookup fails', function () {
    Http::fake([
        'graph.facebook.com/123/subscribed_apps*' => Http::response(['success' => true]),
        'graph.facebook.com/123*' => Http::response(['error' => ['message' => "(#100) ... requires the 'pages_read_engagement' permission ..."]], 400),
    ]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '123', 'page_access_token' => 'valid-leads-token', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success');

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/123/subscribed_apps'
        && $request['subscribed_fields'] === 'leadgen');
});

test('testing a facebook source whose page subscription fails reports failure', function () {
    Http::fake([
        'graph.facebook.com/123/subscribed_apps*' => Http::response(['error' => ['message' => 'Insufficient permission.']], 400),
        'graph.facebook.com/123*' => Http::response(['id' => '123', 'name' => 'My Test Page']),
    ]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'valid-token', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger'
            && str_contains($params['slots']['text'], 'Insufficient permission.'));
});

test('testing a facebook source with an invalid token reports failure', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400)]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'bad-token', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger');
});

test('testing a whatsapp source without a waba id reports success but notes auto-subscription is unavailable', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+1 555-0100', 'verified_name' => 'Acme'])]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '456', 'access_token' => 'valid-token', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success'
            && str_contains($params['slots']['text'], 'WhatsApp Business Account ID'));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'subscribed_apps'));
});

test('testing a whatsapp source with a waba id also subscribes the app and reports success', function () {
    // Reproduces a real production failure: a verified Callback URL with
    // subscribed webhook fields is NOT enough for WhatsApp — Meta also
    // requires an explicit POST /{waba-id}/subscribed_apps call, confirmed
    // live via GET /{waba-id}/subscribed_apps returning an empty list
    // despite a successful webhook verification handshake.
    Http::fake([
        'graph.facebook.com/999/subscribed_apps*' => Http::response(['success' => true]),
        'graph.facebook.com/456*' => Http::response(['display_phone_number' => '+1 555-0100', 'verified_name' => 'Acme']),
    ]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => [
            'phone_number_id' => '456',
            'access_token' => 'valid-token',
            'verify_token' => 'secret',
            'whatsapp_business_account_id' => '999',
        ],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success'
            && str_contains($params['slots']['text'], 'subscribed the app'));

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/999/subscribed_apps'
        && $request->method() === 'POST'
        && $request['access_token'] === 'valid-token');
});

test('testing a whatsapp source whose waba subscription fails reports failure', function () {
    Http::fake([
        'graph.facebook.com/999/subscribed_apps*' => Http::response(['error' => ['message' => 'Insufficient permission.']], 400),
        'graph.facebook.com/456*' => Http::response(['display_phone_number' => '+1 555-0100', 'verified_name' => 'Acme']),
    ]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => [
            'phone_number_id' => '456',
            'access_token' => 'valid-token',
            'verify_token' => 'secret',
            'whatsapp_business_account_id' => '999',
        ],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger'
            && str_contains($params['slots']['text'], 'Insufficient permission.'));
});

test('testing an instagram source authenticated via facebook with a valid token reports success', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['username' => 'acme_shop', 'name' => 'Acme Shop'])]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'facebook', 'instagram_account_id' => '789', 'access_token' => 'valid-token', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success');

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/789?fields=username%2Cname&access_token=valid-token');
});

test('testing an instagram source authenticated via instagram login with a valid token reports success', function () {
    Http::fake(['graph.instagram.com/*' => Http::response(['username' => 'acme_shop', 'name' => 'Acme Shop'])]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'instagram', 'instagram_account_id' => '789', 'access_token' => 'valid-igaa-token', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success');

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.instagram.com/789?fields=username%2Cname&access_token=valid-igaa-token');
});

test('testing an instagram source with an invalid token reports failure', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400)]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'facebook', 'instagram_account_id' => '789', 'access_token' => 'bad-token', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger');
});

test('testing an instagram source with missing config reports failure without an http call', function () {
    Http::fake();

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'facebook', 'instagram_account_id' => '789', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger');

    Http::assertNothingSent();
});

test('creating an instagram source requires choosing a login method before it can be saved', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->set('type', LeadSourceType::Instagram->value)
        ->set('name', 'Instagram DMs')
        ->set('config.instagram_account_id', '789')
        ->set('config.access_token', 'a-token')
        ->set('config.verify_token', 'a-secret')
        ->call('save')
        ->assertHasErrors(['config.auth_mode' => 'required']);

    expect(LeadSource::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('creating an instagram source with a chosen login method saves the mode alongside the token', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->set('type', LeadSourceType::Instagram->value)
        ->set('name', 'Instagram DMs')
        ->set('config.auth_mode', 'instagram')
        ->set('config.instagram_account_id', '789')
        ->set('config.access_token', 'a-token')
        ->set('config.verify_token', 'a-secret')
        ->call('save')
        ->assertHasNoErrors();

    $source = LeadSource::where('tenant_id', $tenant->id)->firstOrFail();
    expect($source->config)->toMatchArray([
        'auth_mode' => 'instagram',
        'instagram_account_id' => '789',
        'access_token' => 'a-token',
    ]);
});

test('testing a facebook source with missing config reports failure without an http call', function () {
    Http::fake();

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'verify_token' => 'secret'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger');

    Http::assertNothingSent();
});
