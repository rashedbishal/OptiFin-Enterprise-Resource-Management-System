<?php

use App\Enums\CallingApiError;
use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Services\WhatsAppCallingClient;
use App\Support\CallingApiResult;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppCallFixtures as Wa;

const WCC_TOKEN = 'EAAG-sentinel-token-7c1d';

const WCC_SETTINGS_URL = 'https://graph.facebook.com/v25.0/436666719526789/settings';

const WCC_CALLS_URL = 'https://graph.facebook.com/v25.0/436666719526789/calls';

function wccSource(array $overrides = [], array $config = []): LeadSource
{
    return Wa::source(Tenant::factory()->create(), $overrides, array_merge(['access_token' => WCC_TOKEN], $config));
}

function wccClient(array $overrides = [], array $config = []): WhatsAppCallingClient
{
    return new WhatsAppCallingClient(wccSource($overrides, $config));
}

/**
 * Fakes one response per attempt, in order (the last repeats); a Throwable
 * entry is thrown instead. Returns the attempt counter — a throwing fake is not
 * recorded by Http::assertSentCount, so attempts are counted here.
 *
 * @param  array<int, mixed>  $steps
 */
function wccFakeSequence(array $steps): ArrayObject
{
    $attempts = new ArrayObject(['count' => 0]);

    Http::fake(function () use ($attempts, $steps) {
        $step = $steps[min($attempts['count'], count($steps) - 1)];
        $attempts['count']++;

        if ($step instanceof Throwable) {
            throw $step;
        }

        return $step;
    });

    return $attempts;
}

function wccTimeout(): ConnectionException
{
    return new ConnectionException('cURL error 28: Operation timed out');
}

function wccMetaError(int $code, int $status = 400, string $message = 'Meta free text that must never surface'): PromiseInterface
{
    return Http::response(['error' => ['message' => $message, 'type' => 'OAuthException', 'code' => $code, 'fbtrace_id' => 'TRACE123']], $status);
}

// ── Requests ────────────────────────────────────────────────────────────────

test('getSettings sends a bearer-token GET to the versioned settings URL with no query string', function () {
    Http::fake(['*' => Http::response(['calling' => ['status' => 'ENABLED']])]);

    wccClient()->getSettings();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === WCC_SETTINGS_URL
        && $request->hasHeader('Authorization', 'Bearer '.WCC_TOKEN)
        && ! str_contains($request->url(), '?')
        && ! str_contains($request->url(), WCC_TOKEN)
        && ! str_contains($request->url(), 'include_sip_credentials'));
});

test('the graph version comes from the calling config, not a hard-coded value', function () {
    config(['calling.graph_version' => 'v99.0']);
    Http::fake(['*' => Http::response(['calling' => ['status' => 'ENABLED']])]);

    wccClient()->getSettings();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v99.0/436666719526789/settings');
});

test('the default calling graph version is v25.0', function () {
    expect(config('calling.graph_version'))->toBe('v25.0');
});

test('setCallingStatus posts exactly the documented enable and disable bodies as JSON', function (bool $enable, string $status) {
    Http::fake(['*' => Http::response(['success' => true])]);

    $result = wccClient()->setCallingStatus($enable);

    expect($result->successful)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WCC_SETTINGS_URL
        && $request->hasHeader('Authorization', 'Bearer '.WCC_TOKEN)
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->data() === ['calling' => ['status' => $status]]
        && ! str_contains($request->url(), 'include_sip_credentials'));
})->with([
    'enable' => [true, 'ENABLED'],
    'disable' => [false, 'DISABLED'],
]);

test('preAccept and accept post the documented body with an answer session to the calls URL', function (string $method, string $action) {
    Http::fake(['*' => Http::response(['messaging_product' => 'whatsapp', 'success' => true])]);

    $result = wccClient()->{$method}('wacid.CALL-1', Wa::sdp());

    expect($result->successful)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === WCC_CALLS_URL
        && $request->hasHeader('Authorization', 'Bearer '.WCC_TOKEN)
        && $request->data() === [
            'messaging_product' => 'whatsapp',
            'call_id' => 'wacid.CALL-1',
            'action' => $action,
            'session' => ['sdp_type' => 'answer', 'sdp' => Wa::sdp()],
        ]);
})->with([
    'pre_accept' => ['preAccept', 'pre_accept'],
    'accept' => ['accept', 'accept'],
]);

test('reject and terminate post the documented body with no session', function (string $method, string $action) {
    Http::fake(['*' => Http::response(['messaging_product' => 'whatsapp', 'success' => true])]);

    $result = wccClient()->{$method}('wacid.CALL-1');

    expect($result->successful)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === WCC_CALLS_URL
        && $request->data() === ['messaging_product' => 'whatsapp', 'call_id' => 'wacid.CALL-1', 'action' => $action]);
})->with([
    'reject' => ['reject', 'reject'],
    'terminate' => ['terminate', 'terminate'],
]);

test('each client uses only its own source\'s phone number id and token', function () {
    Http::fake(['*' => Http::response(['calling' => ['status' => 'DISABLED']])]);

    $other = Wa::source(Tenant::factory()->create(), [], ['phone_number_id' => '999000111', 'access_token' => 'other-token']);

    (new WhatsAppCallingClient($other))->getSettings();
    wccClient()->getSettings();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/999000111/settings'
        && $request->hasHeader('Authorization', 'Bearer other-token'));
    Http::assertSent(fn (Request $request): bool => $request->url() === WCC_SETTINGS_URL
        && $request->hasHeader('Authorization', 'Bearer '.WCC_TOKEN));
});

test('a source without a phone number id or token fails validation without any HTTP request', function (array $config) {
    Http::fake();

    $source = Wa::source(Tenant::factory()->create(), [], $config);
    $result = (new WhatsAppCallingClient($source))->getSettings();

    expect($result->successful)->toBeFalse()->and($result->error)->toBe(CallingApiError::Validation);
    Http::assertNothingSent();
})->with([
    'no token' => [[]],
    'empty token' => [['access_token' => '']],
    'empty phone number id' => [['access_token' => 'x', 'phone_number_id' => '']],
]);

test('the client refuses a non-WhatsApp source', function () {
    $source = LeadSource::factory()->create([
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => WCC_TOKEN, 'verify_token' => 'v'],
    ]);

    expect(fn () => new WhatsAppCallingClient($source))->toThrow(InvalidArgumentException::class);
});

// ── Parsing ─────────────────────────────────────────────────────────────────

test('getSettings parses the calling status and SIP status', function () {
    Http::fake(['*' => Http::response(['calling' => ['status' => 'enabled', 'sip' => ['status' => 'DISABLED']]])]);

    $result = wccClient()->getSettings();

    expect($result->successful)->toBeTrue()
        ->and($result->data['calling_status'])->toBe('ENABLED')
        ->and($result->data['sip_status'])->toBe('DISABLED')
        ->and($result->data['restrictions'])->toBe([])
        ->and($result->httpStatus)->toBe(200);
});

test('a settings response without a calling status parses as unknown rather than failing', function () {
    Http::fake(['*' => Http::response(['messaging_product' => 'whatsapp'])]);

    $result = wccClient()->getSettings();

    expect($result->successful)->toBeTrue()->and($result->data['calling_status'])->toBeNull();
});

test('restrictions are parsed defensively whichever way Meta spells and places the list', function (array $body) {
    Http::fake(['*' => Http::response($body)]);

    $result = wccClient()->getSettings();

    expect($result->data['restrictions'])->toBe([
        ['type' => 'RESTRICTED_USER_INITIATED_CALLING', 'expiration' => 1_700_000_000],
        ['type' => 'RESTRICTED_BUSINESS_INITIATED_CALLING', 'expiration' => null],
    ]);
})->with(function () {
    $items = [
        ['type' => 'RESTRICTED_USER_INITIATED_CALLING', 'reason' => 'free text ignored', 'expiration' => '1700000000'],
        ['type' => 'RESTRICTED_BUSINESS_INITIATED_CALLING'],
        ['reason' => 'no type, dropped'],
        'not-an-array',
    ];

    return [
        'calling.restrictions list' => [['calling' => ['status' => 'ENABLED', 'restrictions' => $items]]],
        'calling.restriction_list' => [['calling' => ['status' => 'ENABLED', 'restriction_list' => $items]]],
        'nested restrictions_list' => [['calling' => ['status' => 'ENABLED', 'restrictions' => ['restrictions_list' => $items]]]],
        'top level' => [['calling' => ['status' => 'ENABLED'], 'restrictions_list' => $items]],
    ];
});

test('the parsed result never carries Meta\'s free text', function () {
    Http::fake(['*' => Http::response(['calling' => ['status' => 'ENABLED', 'restrictions' => [['type' => 'RESTRICTED_USER_INITIATED_CALLING', 'reason' => 'SECRET-REASON-TEXT']]]])]);

    expect(json_encode(wccClient()->getSettings()))->not->toContain('SECRET-REASON-TEXT');
});

// ── Error normalisation ─────────────────────────────────────────────────────

test('Meta error codes map to deterministic categories', function (int $code, int $status, CallingApiError $expected) {
    Http::fake(['*' => wccMetaError($code, $status)]);

    $result = wccClient()->setCallingStatus(true);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe($expected)
        ->and($result->metaCode)->toBe($code)
        ->and($result->httpStatus)->toBe($status)
        ->and($result->fbtraceId)->toBe('TRACE123')
        ->and($result->userMessage())->toBe($expected->userMessage())
        ->and($result->userMessage())->not->toContain('Meta free text');
})->with([
    'calling not enabled' => [138000, 401, CallingApiError::CallingNotEnabled],
    'temporarily disabled' => [138014, 400, CallingApiError::TemporarilyDisabled],
    'cannot be enabled' => [138015, 400, CallingApiError::NotEligible],
    'prerequisites not met' => [138018, 400, CallingApiError::NotEligible],
    'business calling unavailable' => [138013, 400, CallingApiError::NotEligible],
    'sip number' => [131055, 400, CallingApiError::SipNumber],
    'payment' => [131044, 400, CallingApiError::Payment],
    'concurrent limit' => [138002, 429, CallingApiError::Limit],
    'rate limit' => [138005, 429, CallingApiError::Limit],
    'app rate limit' => [613, 400, CallingApiError::Limit],
    'connection error' => [138004, 500, CallingApiError::Transient],
    'connect timeout' => [138007, 500, CallingApiError::Transient],
    'transient 138021' => [138021, 400, CallingApiError::Transient],
    'expired token' => [190, 401, CallingApiError::Authentication],
    'missing permission' => [200, 403, CallingApiError::Permission],
    'invalid parameter' => [100, 400, CallingApiError::Validation],
]);

test('an unmapped code falls back on the HTTP status', function (int $status, CallingApiError $expected) {
    Http::fake(['*' => wccMetaError(424242, $status)]);

    expect(wccClient()->setCallingStatus(true)->error)->toBe($expected);
})->with([
    401 => [401, CallingApiError::Authentication],
    403 => [403, CallingApiError::Permission],
    400 => [400, CallingApiError::Validation],
    404 => [404, CallingApiError::Validation],
    429 => [429, CallingApiError::Limit],
    503 => [503, CallingApiError::Transient],
]);

test('a non-JSON error body is classified by its HTTP status alone', function (int $status, CallingApiError $expected) {
    Http::fake(['*' => Http::response('<html>Bad gateway</html>', $status)]);

    $result = wccClient()->setCallingStatus(true);

    expect($result->error)->toBe($expected)->and($result->metaCode)->toBeNull()->and($result->fbtraceId)->toBeNull();
})->with([
    'gateway' => [502, CallingApiError::Transient],
    'bad request' => [400, CallingApiError::Validation],
]);

test('a JSON error without an error object is classified by its HTTP status', function () {
    Http::fake(['*' => Http::response(['unexpected' => true], 400)]);

    expect(wccClient()->setCallingStatus(true)->error)->toBe(CallingApiError::Validation);
});

test('a 2xx that is not the documented body is an invalid response', function (mixed $body) {
    Http::fake(['*' => Http::response($body, 200)]);

    expect(wccClient()->setCallingStatus(true)->error)->toBe(CallingApiError::InvalidResponse);
})->with([
    'success false' => [['success' => false]],
    'no success key' => [['id' => '1']],
    'html' => ['<html>ok</html>'],
    'empty' => [''],
]);

test('a non-JSON 2xx settings body is an invalid response', function () {
    Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

    $result = wccClient()->getSettings();

    expect($result->successful)->toBeFalse()->and($result->error)->toBe(CallingApiError::InvalidResponse);
});

test('a timeout or connection failure is a network error', function () {
    wccFakeSequence([wccTimeout()]);

    $result = wccClient()->setCallingStatus(true);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe(CallingApiError::Network)
        ->and($result->httpStatus)->toBeNull();
});

// ── Retry policy ────────────────────────────────────────────────────────────

test('GET settings is retried exactly once after a connection failure', function () {
    $attempts = wccFakeSequence([wccTimeout(), Http::response(['calling' => ['status' => 'ENABLED']])]);

    $result = wccClient()->getSettings();

    expect($result->successful)->toBeTrue()->and($attempts['count'])->toBe(2);
});

test('GET settings is retried exactly once after a 5xx, and no more', function () {
    $attempts = wccFakeSequence([wccMetaError(138004, 500)]);

    $result = wccClient()->getSettings();

    expect($result->successful)->toBeFalse()->and($result->error)->toBe(CallingApiError::Transient)->and($attempts['count'])->toBe(2);
});

test('GET settings that fails twice by connection reports a network error after exactly two attempts', function () {
    $attempts = wccFakeSequence([wccTimeout()]);

    $result = wccClient()->getSettings();

    expect($result->error)->toBe(CallingApiError::Network)->and($attempts['count'])->toBe(2);
});

test('GET settings is not retried after a 4xx', function () {
    Http::fake(['*' => wccMetaError(190, 401)]);

    wccClient()->getSettings();

    Http::assertSentCount(1);
});

test('no POST is ever retried, whether it times out or gets a 5xx', function (string $method, array $args, string $failure) {
    $attempts = wccFakeSequence([$failure === 'timeout' ? wccTimeout() : wccMetaError(138004, 500)]);

    $result = wccClient()->{$method}(...$args);

    expect($result->successful)->toBeFalse()->and($attempts['count'])->toBe(1);
})->with(function () {
    $cases = [];

    foreach ([
        'setCallingStatus' => [[true]],
        'preAccept' => [['wacid.1', 'sdp']],
        'accept' => [['wacid.1', 'sdp']],
        'reject' => [['wacid.1']],
        'terminate' => [['wacid.1']],
    ] as $method => [$args]) {
        $cases["$method timeout"] = [$method, $args, 'timeout'];
        $cases["$method 500"] = [$method, $args, '500'];
    }

    return $cases;
});

// ── Credential safety ───────────────────────────────────────────────────────

test('no result, exception or dump ever contains the access token, an SDP or Meta\'s raw text', function () {
    Http::fake(['*' => wccMetaError(190, 401, 'Invalid OAuth access token '.WCC_TOKEN.' for '.Wa::CALLER)]);
    $client = wccClient();

    $results = [
        $client->getSettings(),
        $client->setCallingStatus(true),
        $client->preAccept('wacid.1', Wa::sdp()),
        $client->reject('wacid.1'),
    ];

    foreach ($results as $result) {
        expect($result)->toBeInstanceOf(CallingApiResult::class);

        foreach ([json_encode($result), print_r($result, true), serialize($result), $result->userMessage()] as $dump) {
            expect($dump)
                ->not->toContain(WCC_TOKEN)
                ->not->toContain(Wa::SDP_SENTINEL)
                ->not->toContain(Wa::CALLER)
                ->not->toContain('Invalid OAuth');
        }
    }
});

test('a connection failure result does not carry the exception text', function () {
    wccFakeSequence([new ConnectionException('cURL error 28 for '.WCC_TOKEN)]);

    $result = wccClient()->getSettings();

    expect(print_r($result, true).json_encode($result))->not->toContain(WCC_TOKEN)->not->toContain('cURL');
});
