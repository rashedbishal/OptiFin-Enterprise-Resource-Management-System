<?php

use App\Enums\LeadSourceType;
use App\Events\LeadReceived;
use App\Http\Middleware\ThrottleRequestsFailOpen;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * SEC-2 — rate limiting for the legacy website-form webhook
 * (App\Http\Controllers\Api\LeadWebhookController::websiteForm()), which
 * previously had none. See App\Providers\AppServiceProvider::
 * configureRateLimiting() for the two named limiters
 * (legacy-website-form-per-source-ip, legacy-website-form-per-source) and
 * App\Http\Middleware\ThrottleRequestsFailOpen for why a dedicated
 * middleware evaluates them instead of the stock `throttle:` middleware.
 *
 * No manual cache clearing between tests: the `cache` table lives on the
 * same connection RefreshDatabase wraps in a per-test transaction, so
 * rate-limit state is rolled back with everything else — the same
 * assumption the existing builder-route throttle tests in
 * WebsiteFormSubmissionTest.php already rely on without any explicit flush.
 */
function createLegacyWebsiteFormSource(Tenant $tenant, string $apiKey = 'legacy-key-123'): LeadSource
{
    return LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => $apiKey],
    ]);
}

function postLegacyWebsiteForm(Tenant $tenant, string $apiKey, array $data = []): TestResponse
{
    return test()->postJson(
        route('api.webhook.website-form', $tenant->tenant_code),
        array_merge(['name' => 'Visitor', 'email' => 'visitor+'.Str::random(8).'@example.com'], $data),
        ['X-Api-Key' => $apiKey],
    );
}

// --- A. Under-limit success -------------------------------------------

test('requests within both ceilings succeed and each creates a lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = createLegacyWebsiteFormSource($tenant);

    for ($i = 0; $i < 5; $i++) {
        postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => "visitor{$i}@example.com"])->assertOk();
    }

    expect(Lead::where('source_id', $source->id)->count())->toBe(5);
});

// --- B. Per-IP boundary --------------------------------------------------

test('the 20th request from one source+IP succeeds and the 21st is rejected with no lead created', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenant);

    for ($i = 0; $i < 20; $i++) {
        postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => "ip-boundary-{$i}@example.com"])->assertOk();
    }

    $before = Lead::count();

    postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => 'one-too-many@example.com'])->assertStatus(429);

    expect(Lead::count())->toBe($before);
});

// --- C. Source-wide boundary ----------------------------------------------

test('the source-wide ceiling trips at request 61 even when spread across IPs that individually stay under the per-IP limit', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenant);

    $ips = ['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4'];

    foreach ($ips as $ip) {
        test()->withServerVariables(['REMOTE_ADDR' => $ip]);

        for ($i = 0; $i < 15; $i++) {
            postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => "{$ip}-{$i}@example.com"])->assertOk();
        }
    }

    // 60 total, none of the 4 IPs individually reached the 20/min per-IP
    // ceiling — only the 60/min source-wide ceiling can be the one that
    // trips next.
    expect(Lead::count())->toBe(60);

    test()->withServerVariables(['REMOTE_ADDR' => '10.0.0.5']);
    $before = Lead::count();

    postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => 'sixty-first@example.com'])->assertStatus(429);

    expect(Lead::count())->toBe($before);
});

// --- D. Multiple IP behavior -----------------------------------------------

test('one IP exhausting its own per-IP bucket does not block a different IP against the same source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenant);

    test()->withServerVariables(['REMOTE_ADDR' => '10.0.1.1']);
    for ($i = 0; $i < 20; $i++) {
        postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => "ip1-{$i}@example.com"])->assertOk();
    }
    postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => 'ip1-blocked@example.com'])->assertStatus(429);

    // A different IP against the same source is still well under the
    // shared 60/min source-wide ceiling (only 20 consumed by IP1 so far)
    // and has its own, independent 20/min per-IP bucket.
    test()->withServerVariables(['REMOTE_ADDR' => '10.0.1.2']);
    postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => 'ip2-first@example.com'])->assertOk();

    expect(Lead::where('email', 'ip2-first@example.com')->exists())->toBeTrue();
});

// --- E. Source isolation ---------------------------------------------------

test('one WebsiteForm source exhausting its limiter does not affect a different source', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenantA, 'key-a');
    createLegacyWebsiteFormSource($tenantB, 'key-b');

    for ($i = 0; $i < 20; $i++) {
        postLegacyWebsiteForm($tenantA, 'key-a', ['email' => "a-{$i}@example.com"])->assertOk();
    }
    postLegacyWebsiteForm($tenantA, 'key-a', ['email' => 'a-blocked@example.com'])->assertStatus(429);

    // Source B, a completely independent LeadSource, is unaffected — same
    // REMOTE_ADDR (127.0.0.1 default) is irrelevant here since the key is
    // scoped by source id, not shared across sources.
    postLegacyWebsiteForm($tenantB, 'key-b', ['email' => 'b-first@example.com'])->assertOk();

    expect(Lead::where('email', 'b-first@example.com')->exists())->toBeTrue();
});

// --- F. Tenant isolation ----------------------------------------------------

test('one tenant cannot consume another tenant\'s legacy website-form rate-limit bucket', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenantA, 'tenant-a-key');
    createLegacyWebsiteFormSource($tenantB, 'tenant-b-key');

    for ($i = 0; $i < 20; $i++) {
        postLegacyWebsiteForm($tenantA, 'tenant-a-key', ['email' => "ta-{$i}@example.com"])->assertOk();
    }
    postLegacyWebsiteForm($tenantA, 'tenant-a-key', ['email' => 'ta-blocked@example.com'])->assertStatus(429);

    postLegacyWebsiteForm($tenantB, 'tenant-b-key', ['email' => 'tb-first@example.com'])->assertOk();

    expect(Lead::where('email', 'tb-first@example.com')->exists())->toBeTrue();
});

// --- G. Distinct payloads ----------------------------------------------------

test('varying name/email/phone/message on every request does not bypass the rate limit', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenant);

    for ($i = 0; $i < 20; $i++) {
        postLegacyWebsiteForm($tenant, 'legacy-key-123', [
            'name' => "Visitor {$i}",
            'email' => "distinct-{$i}@example.com",
            'phone' => "+1555000{$i}",
            'message' => "Message body number {$i}",
        ])->assertOk();
    }

    postLegacyWebsiteForm($tenant, 'legacy-key-123', [
        'name' => 'Visitor 21',
        'email' => 'distinct-21@example.com',
        'phone' => '+15550021',
        'message' => 'Message body number 21',
    ])->assertStatus(429);

    expect(Lead::where('source_id', LeadSource::where('tenant_id', $tenant->id)->first()->id)->count())->toBe(20);
});

// --- H. Same payload / REL-2 interaction --------------------------------------

test('rapid identical submissions still count against the rate limit, while REL-2 independently still prevents a duplicate lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenant);

    $payload = ['name' => 'Same Visitor', 'email' => 'same@example.com', 'phone' => null, 'message' => null];

    // Two identical submissions, both accepted by the rate limiter (well
    // under 20), but REL-2's own unmodified idempotency window means only
    // one Lead is actually created.
    postLegacyWebsiteForm($tenant, 'legacy-key-123', $payload)->assertOk();
    postLegacyWebsiteForm($tenant, 'legacy-key-123', $payload)->assertOk();

    expect(Lead::where('email', 'same@example.com')->count())->toBe(1);

    // Confirm the rate limiter still counted both — 18 more identical
    // submissions (20 total) still succeed, the 21st is rejected, proving
    // REL-2 suppressing the Lead did not also suppress the rate-limit hit.
    for ($i = 0; $i < 18; $i++) {
        postLegacyWebsiteForm($tenant, 'legacy-key-123', $payload)->assertOk();
    }

    postLegacyWebsiteForm($tenant, 'legacy-key-123', $payload)->assertStatus(429);
});

// --- I. 429 behavior -----------------------------------------------------

test('an over-limit request returns 429, creates no lead, and never dispatches LeadReceived', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenant);

    for ($i = 0; $i < 20; $i++) {
        postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => "warmup-{$i}@example.com"])->assertOk();
    }

    Event::fake([LeadReceived::class]);
    $before = Lead::count();

    postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => 'rejected@example.com'])->assertStatus(429);

    expect(Lead::count())->toBe($before);
    Event::assertNotDispatched(LeadReceived::class);
});

// --- J. Concurrency (best-effort, single-process) -----------------------------

test('rapid back-to-back requests against the same key are counted correctly with no off-by-one', function () {
    // A genuinely concurrent, multi-process/multi-connection race is out of
    // scope for this suite (SQLite, single connection, single process) —
    // this proves the counting itself is exact under rapid sequential
    // dispatch, relying on Laravel's own atomic cache increment()
    // guarantee for cross-process correctness, not re-verifying that
    // guarantee here.
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenant);

    $statuses = [];

    for ($i = 0; $i < 21; $i++) {
        $statuses[] = postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => "rapid-{$i}@example.com"])->getStatusCode();
    }

    expect($statuses)->toBe([...array_fill(0, 20, 200), 429]);
});

// --- K. Limiter backend failure (fail open) -----------------------------------

test('a limiter backend failure fails open, logs a warning, and still creates the lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenant);

    Log::spy();

    // Wraps the REAL, already-configured RateLimiter singleton (the one
    // AppServiceProvider::boot() already registered both named legacy
    // limiters against) rather than constructing a disconnected fresh
    // instance — Mockery::mock($existingInstance)->makePartial() proxies
    // every un-stubbed call to that same real object, preserving its
    // already-registered limiters.
    $real = app(RateLimiter::class);
    $mock = Mockery::mock($real)->makePartial();
    $mock->shouldReceive('tooManyAttempts')->andThrow(new RuntimeException('simulated cache backend failure'));
    $this->app->instance(RateLimiter::class, $mock);

    postLegacyWebsiteForm($tenant, 'legacy-key-123', ['email' => 'failopen@example.com'])->assertOk();

    expect(Lead::where('email', 'failopen@example.com')->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

test('unrelated application exceptions are never caught by the fail-open middleware', function () {
    // The middleware's try/catch wraps only tooManyAttempts()/hit() — a
    // failure anywhere else (here, simulated inside the limiter's own
    // resolution closure, which AppServiceProvider registers and which
    // runs OUTSIDE the middleware's try/catch) must propagate normally,
    // not be swallowed as if it were a rate-limiter backend failure.
    // withoutExceptionHandling() is required here — otherwise Laravel's
    // test HTTP client renders the exception as a 500 response instead of
    // letting it bubble up to this test's own toThrow() assertion.
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createLegacyWebsiteFormSource($tenant);
    $this->withoutExceptionHandling();

    $real = app(RateLimiter::class);
    $mock = Mockery::mock($real)->makePartial();
    $mock->shouldReceive('limiter')->andReturn(function () {
        throw new RuntimeException('unrelated failure, not a cache backend failure');
    });
    $this->app->instance(RateLimiter::class, $mock);

    expect(fn () => postLegacyWebsiteForm($tenant, 'legacy-key-123'))
        ->toThrow(RuntimeException::class, 'unrelated failure, not a cache backend failure');
});

// --- L. Missing named limiter --------------------------------------------

test('an unregistered named limiter fails open and logs an error, without touching application state', function () {
    Log::spy();

    $middleware = app(ThrottleRequestsFailOpen::class);
    $request = Request::create('/api/webhook/website-form/does-not-matter', 'POST');
    $nextWasCalled = false;

    $response = $middleware->handle($request, function ($req) use (&$nextWasCalled) {
        $nextWasCalled = true;

        return response()->json(['status' => 'ok']);
    }, 'sec2-test-unregistered-limiter-name');

    expect($nextWasCalled)->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
    Log::shouldHaveReceived('error')->atLeast()->once();
});
