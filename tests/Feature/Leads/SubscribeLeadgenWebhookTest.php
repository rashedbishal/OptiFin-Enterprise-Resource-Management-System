<?php

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use Illuminate\Support\Facades\Http;

test('subscribing a specific source calls the graph api with the leadgen field', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    Http::fake([
        'graph.facebook.com/1087258341146975/subscribed_apps*' => Http::sequence()
            ->push(['success' => true])
            ->push(['data' => [['subscribed_fields' => ['leadgen']]]]),
    ]);

    $this->artisan('meta:subscribe-leadgen', ['source' => $source->id])->assertExitCode(0);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains((string) $request->url(), 'subscribed_apps')
        && $request['subscribed_fields'] === 'leadgen'
        && $request['access_token'] === 'page-token');
});

test('subscribing with no matching source fails cleanly', function () {
    $this->artisan('meta:subscribe-leadgen', ['source' => 'does-not-exist'])->assertExitCode(1);
});

test('subscribing without an argument subscribes every active facebook lead ads source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => 'page-a', 'page_access_token' => 'token-a', 'verify_token' => 'secret'],
    ]);
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => 'page-b', 'page_access_token' => 'token-b', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $this->artisan('meta:subscribe-leadgen')->assertExitCode(0);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'page-a/subscribed_apps'));
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'page-b/subscribed_apps'));
});
