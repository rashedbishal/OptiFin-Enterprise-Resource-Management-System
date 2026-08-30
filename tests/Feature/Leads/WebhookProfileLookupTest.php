<?php

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function messengerPayload(string $psid, ?string $text = null): array
{
    return [
        'entry' => [[
            'messaging' => [array_filter([
                'sender' => ['id' => $psid],
                'message' => $text !== null ? ['text' => $text] : null,
            ])],
        ]],
    ];
}

test('a facebook lead falls back to "Facebook User {psid}" when the profile lookup fails', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'bad-token', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 400)]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), messengerPayload('27181450094857149', 'Hi'))->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->name)->toBe('Facebook User 27181450094857149');
});

test('a facebook lead created with a fallback name self-heals on the next message once the profile lookup succeeds', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    // A single fake registered once, with a sequence: calling Http::fake()
    // a second time mid-test doesn't replace the first stub, it stacks on
    // top of it — the earlier (failing) stub would keep matching and win.
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'temporary']], 500)
        ->push(['name' => 'Saadman Rahman', 'profile_pic' => 'https://example.com/pic.jpg'])]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), messengerPayload('27181450094857149', 'Hi'))->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->name)->toBe('Facebook User 27181450094857149');

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), messengerPayload('27181450094857149', 'Still there?'))->assertOk();

    $lead->refresh();
    expect($lead->name)->toBe('Saadman Rahman');
    expect($lead->avatarUrl())->toBe('https://example.com/pic.jpg');
});

test('a successful facebook profile lookup is cached for 24 hours', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Saadman Rahman', 'profile_pic' => null])]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), messengerPayload('27181450094857149', 'Hi'))->assertOk();

    expect(Cache::get('fb_profile_27181450094857149'))->toBe(['name' => 'Saadman Rahman', 'profile_pic' => null]);
    Http::assertSentCount(1);
});

test('a cached profile is used instead of hitting the graph api again', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '123', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    // Lead created while the lookup was failing — name stuck on the fallback.
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'temporary']], 500)]);
    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), messengerPayload('27181450094857149', 'Hi'))->assertOk();

    // Simulates the profile having already been cached by an earlier,
    // successful lookup (e.g. from a near-duplicate webhook delivery).
    Cache::put('fb_profile_27181450094857149', ['name' => 'Cached Name', 'profile_pic' => null], now()->addHours(24));

    Http::fake(['graph.facebook.com/*' => fn () => Http::response(['error' => 'must not be called'], 500)]);

    $this->postJson(route('api.webhook.facebook', $tenant->tenant_code), messengerPayload('27181450094857149', 'Second message'))->assertOk();

    Http::assertNothingSent();
    expect(Lead::where('tenant_id', $tenant->id)->first()->name)->toBe('Cached Name');
});

test('an instagram lead created with a fallback name self-heals on the next message once the profile lookup succeeds', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Instagram,
        'config' => ['auth_mode' => 'facebook', 'instagram_account_id' => '789', 'access_token' => 'ig-token', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'temporary']], 500)
        ->push(['username' => 'osl.web.bd', 'profile_pic' => 'https://example.com/ig.jpg'])]);

    $payload = [
        'entry' => [[
            'messaging' => [[
                'sender' => ['id' => '2335756800557496'],
                'message' => ['text' => 'Hi'],
            ]],
        ]],
    ];

    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->name)->toBe('Instagram User 2335756800557496');

    $payload['entry'][0]['messaging'][0]['message']['text'] = 'Still there?';
    $this->postJson(route('api.webhook.instagram', $tenant->tenant_code), $payload)->assertOk();

    $lead->refresh();
    expect($lead->name)->toBe('osl.web.bd');
    expect($lead->avatarUrl())->toBe('https://example.com/ig.jpg');
});
