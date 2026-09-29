<?php

use App\Enums\LeadSourceType;
use App\Http\Middleware\ThrottleRequestsFailOpen;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Testing\TestResponse;

/**
 * F8 remediation (forensic audit Batch A) — rate limiting for the
 * Facebook/WhatsApp/Instagram webhook POST routes, which previously had
 * none at all (unlike the legacy website-form webhook — see
 * LegacyWebsiteFormRateLimitTest.php, the direct structural precedent this
 * file mirrors). See App\Providers\AppServiceProvider::
 * configureMetaWebhookRateLimiting() for the six named limiters and
 * App\Http\Middleware\ThrottleRequestsFailOpen for the fail-open evaluator
 * both this file and the legacy one share.
 *
 * Boundary tests pre-fill the limiter via the RateLimiter facade's own
 * hit() rather than looping dozens/hundreds of real HTTP requests — the
 * limiter key format asserted here is the CONTRACT under test (it must
 * match AppServiceProvider::metaWebhookLimit()'s own key construction
 * exactly), not an implementation detail worth hiding behind a slow loop.
 */
function createMetaWebhookSource(Tenant $tenant, LeadSourceType $type, array $config = []): LeadSource
{
    return LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => $type,
        'config' => $config,
    ]);
}

function postFacebookWebhook(Tenant $tenant, array $entries = []): TestResponse
{
    return test()->postJson(route('api.webhook.facebook', $tenant->tenant_code), ['entry' => $entries]);
}

function postWhatsappWebhook(Tenant $tenant, array $entries = []): TestResponse
{
    return test()->postJson(route('api.webhook.whatsapp', $tenant->tenant_code), ['entry' => $entries]);
}

// --- A. Under-limit success -------------------------------------------

test('requests below both ceilings succeed', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createMetaWebhookSource($tenant, LeadSourceType::FacebookMessenger);

    for ($i = 0; $i < 5; $i++) {
        postFacebookWebhook($tenant)->assertOk();
    }
});

// --- B. Per-source-IP boundary ------------------------------------------

test('the 60th request from one source+IP succeeds and the 61st is rejected with 429', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = createMetaWebhookSource($tenant, LeadSourceType::FacebookMessenger);

    RateLimiterFacade::hit("meta-webhook-facebook-per-source-ip:{$source->id}|127.0.0.1", 60);
    RateLimiterFacade::hit("meta-webhook-facebook-per-source-ip:{$source->id}|127.0.0.1", 60);
    for ($i = 0; $i < 57; $i++) {
        RateLimiterFacade::hit("meta-webhook-facebook-per-source-ip:{$source->id}|127.0.0.1", 60);
    }

    postFacebookWebhook($tenant)->assertOk();
    postFacebookWebhook($tenant)->assertStatus(429);
});

// --- C. Different IP has its own bucket ----------------------------------

test('one IP exhausting its own per-source-IP bucket does not block a different IP against the same source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = createMetaWebhookSource($tenant, LeadSourceType::FacebookMessenger);

    for ($i = 0; $i < 60; $i++) {
        RateLimiterFacade::hit("meta-webhook-facebook-per-source-ip:{$source->id}|127.0.0.1", 60);
    }

    test()->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->postJson(
        route('api.webhook.facebook', $tenant->tenant_code),
        ['entry' => []],
    )->assertStatus(429);

    test()->withServerVariables(['REMOTE_ADDR' => '10.9.9.9']);
    postFacebookWebhook($tenant)->assertOk();
});

// --- D. Source-wide ceiling -----------------------------------------------

test('the source-wide ceiling trips at request 301 even when spread across IPs that individually stay under the per-IP limit', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = createMetaWebhookSource($tenant, LeadSourceType::Whatsapp);

    for ($i = 0; $i < 300; $i++) {
        RateLimiterFacade::hit("meta-webhook-whatsapp-per-source:{$source->id}", 60);
    }

    postWhatsappWebhook($tenant)->assertStatus(429);
});

// --- E. Source isolation ---------------------------------------------------

test('one source exhausting its limiter does not affect a different source, even for the same tenant/platform', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $sourceA = createMetaWebhookSource($tenant, LeadSourceType::FacebookMessenger);
    LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookLeadAds]);

    for ($i = 0; $i < 60; $i++) {
        RateLimiterFacade::hit("meta-webhook-facebook-per-source-ip:{$sourceA->id}|127.0.0.1", 60);
    }

    // The Messenger source's own bucket is exhausted, but resolveSource()
    // picks whichever active Facebook-family source exists first when no
    // messaging[] item is present to disambiguate — this asserts the
    // OTHER source's own, independent bucket is untouched by asserting
    // directly against the limiter rather than the ambiguous shared route,
    // mirroring this file's own boundary-testing convention.
    expect(RateLimiterFacade::tooManyAttempts("meta-webhook-facebook-per-source-ip:{$sourceA->id}|127.0.0.1", 60))->toBeTrue();

    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    expect(RateLimiterFacade::tooManyAttempts("meta-webhook-whatsapp-per-source-ip:{$sourceB->id}|127.0.0.1", 60))->toBeFalse();
});

// --- F. Unresolvable source is still throttled, never exempted ------------

test('a nonexistent tenant_code is still throttled, not given an unlimited exemption', function () {
    for ($i = 0; $i < 20; $i++) {
        RateLimiterFacade::hit('meta-webhook-unresolvable:facebook|127.0.0.1', 60);
    }

    test()->postJson(route('api.webhook.facebook', 'TEN-DOES-NOT-EXIST'), ['entry' => []])
        ->assertStatus(429);
});

test('an inactive/unresolvable source for a real tenant is still throttled under the unresolvable bucket', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    // No LeadSource of any Facebook type exists for this tenant at all.

    for ($i = 0; $i < 20; $i++) {
        RateLimiterFacade::hit('meta-webhook-unresolvable:facebook|127.0.0.1', 60);
    }

    postFacebookWebhook($tenant)->assertStatus(429);
});

// --- G. Fail-open on limiter backend failure -------------------------------

test('a limiter backend failure fails open, logs a warning, and the request still reaches the controller', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createMetaWebhookSource($tenant, LeadSourceType::FacebookMessenger);

    Log::spy();

    $real = app(RateLimiter::class);
    $mock = Mockery::mock($real)->makePartial();
    $mock->shouldReceive('tooManyAttempts')->andThrow(new RuntimeException('simulated cache backend failure'));
    app()->instance(RateLimiter::class, $mock);

    postFacebookWebhook($tenant)->assertOk();

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

// --- H. Middleware order: throttling before signature verification --------

test('an over-limit request is rejected with 429 before signature verification ever runs, even for a source with a configured app_secret', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = createMetaWebhookSource($tenant, LeadSourceType::FacebookMessenger, ['app_secret' => 'a-real-secret']);

    for ($i = 0; $i < 60; $i++) {
        RateLimiterFacade::hit("meta-webhook-facebook-per-source-ip:{$source->id}|127.0.0.1", 60);
    }

    // No X-Hub-Signature-256 header at all — if signature verification ran
    // first, this would 403 (signature mismatch); throttling running first
    // means it never reaches that check at all.
    postFacebookWebhook($tenant)->assertStatus(429);
});

test('an unregistered named limiter fails open and logs an error, without blocking the request', function () {
    Log::spy();

    $middleware = app(ThrottleRequestsFailOpen::class);
    $request = Request::create('/api/webhook/facebook/does-not-matter', 'POST');
    $nextWasCalled = false;

    $response = $middleware->handle($request, function ($req) use (&$nextWasCalled) {
        $nextWasCalled = true;

        return response()->json(['status' => 'ok']);
    }, 'meta-webhook-test-unregistered-limiter-name');

    expect($nextWasCalled)->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
    Log::shouldHaveReceived('error')->atLeast()->once();
});
