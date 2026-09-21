<?php

use App\Actions\Platform\ManageWhatsAppCalling;
use App\Enums\CallingControlState;
use App\Enums\LeadSourceType;
use App\Enums\ModuleScope;
use App\Models\LeadSource;
use App\Models\Module;
use App\Models\Permission;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\RoleModulePermission;
use App\Models\Tenant;
use App\Support\CallingActionResult;
use Database\Seeders\PermissionSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\Support\WhatsAppCallFixtures as Wa;

const WCM_TOKEN = 'EAAG-admin-sentinel-token-51ab';

const WCM_META_TEXT = 'Meta raw error wording that must never surface';

beforeEach(function () {
    // The rollout gate defaults to false; every test that is not about the gate
    // itself runs with it opened, as a dev/test environment would.
    config(['calling.enable_action_available' => true]);
    Carbon::setTestNow('2026-09-21 10:00:00');
});

function wcmSource(?Tenant $tenant = null, array $overrides = [], array $config = []): LeadSource
{
    return Wa::source($tenant ?? Tenant::factory()->create(), array_merge(['calling_enabled' => false], $overrides), array_merge(['access_token' => WCM_TOKEN], $config));
}

function wcmManager(): ManageWhatsAppCalling
{
    return app(ManageWhatsAppCalling::class);
}

/**
 * A Platform Admin who is NOT the mother admin but whose role grants
 * client-management `update` — the real authorization path.
 */
function wcmAuthorizedAdmin(): PlatformUser
{
    (new PermissionSeeder)->run();

    $module = Module::firstOrCreate(
        ['slug' => 'client-management'],
        ['name' => 'Client Management', 'scope' => ModuleScope::Platform, 'sort_order' => 0],
    );
    $role = Role::factory()->create();
    $role->modules()->attach($module->id);
    RoleModulePermission::query()->create([
        'role_id' => $role->id,
        'module_id' => $module->id,
        'permission_id' => Permission::where('slug', 'update')->firstOrFail()->id,
    ]);

    return PlatformUser::factory()->create(['role_id' => $role->id]);
}

/**
 * Fakes Meta by method: POST (settings change) and GET (settings read) get
 * their own responses. Each may be a response, a Throwable (thrown), or a
 * closure run at request time. Returns a log of the requests as "METHOD" strings.
 *
 * @return ArrayObject<int, string>
 */
function wcmFakeMeta(mixed $post = null, mixed $get = null): ArrayObject
{
    Http::swap(new Factory);

    $log = new ArrayObject;
    $post ??= Http::response(['success' => true]);
    $get ??= Http::response(['calling' => ['status' => 'ENABLED']]);

    Http::fake(function (Request $request) use ($log, $post, $get) {
        $log[] = $request->method();
        $step = $request->method() === 'POST' ? $post : $get;

        if ($step instanceof Closure) {
            $step = $step($request);
        }

        if ($step instanceof Throwable) {
            throw $step;
        }

        return $step;
    });

    return $log;
}

function wcmMetaError(int $code, int $status = 400): PromiseInterface
{
    return Http::response(['error' => ['message' => WCM_META_TEXT, 'type' => 'OAuthException', 'code' => $code, 'fbtrace_id' => 'TRACE-ADM']], $status);
}

function wcmExpectVariant(CallingActionResult $result, string $variant): void
{
    expect($result->variant)->toBe($variant);
}

function wcmCaptureLogs(): TestHandler
{
    config([
        'logging.default' => 'wcm-capture',
        'logging.channels.wcm-capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'logging.channels.whatsapp' => ['driver' => 'stack', 'channels' => ['wcm-capture']],
    ]);

    $handler = Log::channel('wcm-capture')->getLogger()->getHandlers()[0];
    expect($handler)->toBeInstanceOf(TestHandler::class);

    return $handler;
}

// ── Rollout gate (D1) ───────────────────────────────────────────────────────

test('enable is blocked while the rollout gate is closed, without touching Meta or the source', function () {
    config(['calling.enable_action_available' => false]);
    $log = wcmFakeMeta();
    $source = wcmSource();

    $result = wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'danger');
    expect($result->message)->toContain('browser answering is not available yet')
        ->and($log)->toHaveCount(0)
        ->and($source->fresh()->calling_enabled)->toBeFalse()
        ->and($source->fresh()->calling_meta_status)->toBeNull();
    Http::assertNothingSent();
});

test('check and disable stay available while the rollout gate is closed', function () {
    config(['calling.enable_action_available' => false]);
    wcmFakeMeta(get: Http::response(['calling' => ['status' => 'ENABLED']]));
    $source = wcmSource(overrides: ['calling_enabled' => true]);
    $admin = PlatformUser::factory()->motherAdmin()->create();

    wcmExpectVariant(wcmManager()->check($admin, $source->tenant, $source->id), 'success');
    wcmExpectVariant(wcmManager()->disable($admin, $source->tenant, $source->id), 'success');

    expect($source->fresh()->calling_enabled)->toBeFalse();
});

test('the gate can be explicitly opened for a dev or test number', function () {
    config(['calling.enable_action_available' => true]);
    wcmFakeMeta();
    $source = wcmSource();

    wcmExpectVariant(wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id), 'success');

    expect($source->fresh()->calling_enabled)->toBeTrue();
});

// ── Authorization and tenant isolation ──────────────────────────────────────

test('a platform user without client-management update permission cannot enable, check or disable', function (string $method) {
    $log = wcmFakeMeta();
    $source = wcmSource();
    $restricted = PlatformUser::factory()->create();

    expect(fn () => wcmManager()->{$method}($restricted, $source->tenant, $source->id))->toThrow(AuthorizationException::class);

    expect($log)->toHaveCount(0)
        ->and($source->fresh()->only(['calling_enabled', 'calling_meta_status', 'calling_meta_checked_at']))
        ->toBe(['calling_enabled' => false, 'calling_meta_status' => null, 'calling_meta_checked_at' => null]);
})->with(['enable', 'check', 'disable']);

test('a non-mother platform admin holding the permission can manage calling', function () {
    wcmFakeMeta();
    $source = wcmSource();

    wcmExpectVariant(wcmManager()->enable(wcmAuthorizedAdmin(), $source->tenant, $source->id), 'success');

    expect($source->fresh()->calling_enabled)->toBeTrue();
});

test('a source id from another tenant is not found and nothing is sent', function (string $method) {
    $log = wcmFakeMeta();
    $tenantA = Tenant::factory()->create();
    $foreign = wcmSource();

    expect(fn () => wcmManager()->{$method}(PlatformUser::factory()->motherAdmin()->create(), $tenantA, $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    expect($log)->toHaveCount(0)
        ->and($foreign->fresh()->calling_meta_checked_at)->toBeNull()
        ->and($foreign->fresh()->calling_enabled)->toBeFalse();
})->with(['enable', 'check', 'disable']);

test('a nonexistent source id is not found', function () {
    expect(fn () => wcmManager()->check(PlatformUser::factory()->motherAdmin()->create(), Tenant::factory()->create(), 'no-such-id'))
        ->toThrow(ModelNotFoundException::class);
});

test('acting on one tenant\'s source never changes another tenant\'s source even with the same phone number id', function () {
    wcmFakeMeta();
    $mine = wcmSource();
    $theirs = wcmSource(overrides: ['calling_enabled' => false]);

    wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $mine->tenant, $mine->id);

    expect($mine->fresh()->calling_enabled)->toBeTrue()
        ->and($theirs->fresh()->calling_enabled)->toBeFalse()
        ->and($theirs->fresh()->calling_meta_status)->toBeNull()
        ->and($theirs->fresh()->calling_meta_checked_at)->toBeNull();
});

test('a non-WhatsApp source is rejected without any request', function (string $method) {
    $log = wcmFakeMeta();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 't', 'verify_token' => 'v'],
    ]);

    $result = wcmManager()->{$method}(PlatformUser::factory()->motherAdmin()->create(), $tenant, $source->id);

    wcmExpectVariant($result, 'danger');
    expect($result->message)->toContain('only be managed for WhatsApp sources')
        ->and($log)->toHaveCount(0)
        ->and($source->fresh()->calling_enabled)->toBeFalse();
})->with(['enable', 'check', 'disable']);

// ── Enable ──────────────────────────────────────────────────────────────────

test('enable posts ENABLED, confirms with a GET, then sets intent and observation together', function () {
    $log = wcmFakeMeta();
    $source = wcmSource();

    $result = wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'success');
    $fresh = $source->fresh();
    expect($log->getArrayCopy())->toBe(['POST', 'GET'])
        ->and($fresh->calling_enabled)->toBeTrue()
        ->and($fresh->calling_meta_status)->toBe('ENABLED')
        ->and($fresh->calling_meta_checked_at->equalTo(Carbon::now()))->toBeTrue()
        ->and(CallingControlState::for($fresh))->toBe(CallingControlState::On);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['calling' => ['status' => 'ENABLED']]);
});

test('enable does nothing local when Meta rejects the POST', function (int $code, string $expectedText) {
    $log = wcmFakeMeta(post: wcmMetaError($code));
    $source = wcmSource();

    $result = wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'danger');
    expect($result->message)->toContain($expectedText)->not->toContain(WCM_META_TEXT)
        ->and($log->getArrayCopy())->toBe(['POST'])
        ->and($source->fresh()->calling_enabled)->toBeFalse()
        ->and($source->fresh()->calling_meta_status)->toBeNull();
})->with([
    'token expired' => [190, 'access token'],
    'no permission' => [200, 'permission'],
    'not eligible' => [138015, 'requirements for calling'],
    'sip number' => [131055, 'SIP'],
    'payment' => [131044, 'payment method'],
]);

test('an enable POST that times out is reported as possibly applied and leaves the source untouched', function () {
    $log = wcmFakeMeta(post: new ConnectionException('cURL error 28 '.WCM_TOKEN));
    $source = wcmSource();

    $result = wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'danger');
    expect($result->message)->toContain('may or may not have reached WhatsApp')->not->toContain(WCM_TOKEN)
        ->and($source->fresh()->calling_enabled)->toBeFalse()
        ->and($log->getArrayCopy())->toBe(['POST']);
});

test('enable that cannot be confirmed by the follow-up GET does not turn the intent on', function (mixed $get) {
    $log = wcmFakeMeta(get: $get);
    $source = wcmSource();

    $result = wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'warning');
    expect($result->message)->toContain('could not be confirmed')
        ->and($source->fresh()->calling_enabled)->toBeFalse()
        ->and($source->fresh()->calling_meta_status)->toBeNull()
        ->and($log->getArrayCopy()[0])->toBe('POST');
})->with([
    'GET fails' => [fn () => wcmMetaError(138004, 500)],
    'GET times out' => [new ConnectionException('timeout')],
    'no status reported' => [fn () => Http::response(['messaging_product' => 'whatsapp'])],
]);

test('enable where Meta reports DISABLED records the observation but keeps the intent off', function () {
    wcmFakeMeta(get: Http::response(['calling' => ['status' => 'DISABLED']]));
    $source = wcmSource();

    $result = wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'warning');
    expect($source->fresh()->calling_enabled)->toBeFalse()
        ->and($source->fresh()->calling_meta_status)->toBe('DISABLED');
});

test('an unconfirmed enable preserves an existing local state', function () {
    wcmFakeMeta(get: wcmMetaError(138004, 500));
    $source = wcmSource(overrides: ['calling_enabled' => true, 'calling_meta_status' => 'ENABLED', 'calling_meta_checked_at' => Carbon::now()->subDay()]);

    wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    $fresh = $source->fresh();
    expect($fresh->calling_enabled)->toBeTrue()
        ->and($fresh->calling_meta_status)->toBe('ENABLED')
        ->and($fresh->calling_meta_checked_at->equalTo(Carbon::now()->subDay()))->toBeTrue();
});

test('enable is refused for an inactive source or missing credentials, without any request', function (array $overrides, array $config, string $expected) {
    $log = wcmFakeMeta();
    $source = wcmSource(overrides: $overrides, config: $config);

    $result = wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'danger');
    expect($result->message)->toContain($expected)
        ->and($log)->toHaveCount(0)
        ->and($source->fresh()->calling_enabled)->toBeFalse();
})->with([
    'inactive' => [['is_active' => false], [], 'Activate this source'],
    'no app secret' => [[], ['app_secret' => ''], 'app secret'],
    'no phone number id' => [[], ['phone_number_id' => ''], 'phone number ID'],
    'no access token' => [[], ['access_token' => ''], 'access token'],
]);

test('enabling twice is harmless', function () {
    wcmFakeMeta();
    $source = wcmSource();
    $admin = PlatformUser::factory()->motherAdmin()->create();

    wcmManager()->enable($admin, $source->tenant, $source->id);
    wcmExpectVariant(wcmManager()->enable($admin, $source->tenant, $source->id), 'success');

    expect($source->fresh()->calling_enabled)->toBeTrue()->and($source->fresh()->calling_meta_status)->toBe('ENABLED');
});

test('an enable whose confirmation is older than a newer observation does not turn the intent on', function () {
    wcmFakeMeta();
    $source = wcmSource(overrides: ['calling_meta_status' => 'DISABLED', 'calling_meta_checked_at' => Carbon::now()->addMinute()]);

    $result = wcmManager()->enable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'warning');
    expect($result->message)->toContain('at the same time')
        ->and($source->fresh()->calling_enabled)->toBeFalse()
        ->and($source->fresh()->calling_meta_status)->toBe('DISABLED');
});

test('two admins enabling at once: the older confirmation loses, the newer one wins, and the state stays coherent', function () {
    $source = wcmSource();
    $admin = PlatformUser::factory()->motherAdmin()->create();
    $nested = false;

    // While the first enable's confirmation GET is "in flight", a second admin's
    // whole enable runs later in time and commits first.
    wcmFakeMeta(get: function () use (&$nested, $source, $admin) {
        if (! $nested) {
            $nested = true;
            Carbon::setTestNow(Carbon::now()->addSeconds(5));
            wcmManager()->enable($admin, $source->tenant, $source->id);
            Carbon::setTestNow(Carbon::now()->subSeconds(5));
        }

        return Http::response(['calling' => ['status' => 'ENABLED']]);
    });

    $first = wcmManager()->enable($admin, $source->tenant, $source->id);

    $fresh = $source->fresh();
    wcmExpectVariant($first, 'warning');
    expect($fresh->calling_enabled)->toBeTrue()
        ->and($fresh->calling_meta_status)->toBe('ENABLED')
        ->and($fresh->calling_meta_checked_at->equalTo(Carbon::parse('2026-09-21 10:00:05')))->toBeTrue();
});

// ── Check ───────────────────────────────────────────────────────────────────

test('check records only the observation and never changes the local intent', function (bool $local, string $meta) {
    wcmFakeMeta(get: Http::response(['calling' => ['status' => $meta]]));
    $source = wcmSource(overrides: ['calling_enabled' => $local]);

    wcmManager()->check(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    $fresh = $source->fresh();
    expect($fresh->calling_enabled)->toBe($local)
        ->and($fresh->calling_meta_status)->toBe($meta)
        ->and($fresh->calling_meta_checked_at->equalTo(Carbon::now()))->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
})->with([
    'local on, meta on' => [true, 'ENABLED'],
    'local on, meta off' => [true, 'DISABLED'],
    'local off, meta on' => [false, 'ENABLED'],
    'local off, meta off' => [false, 'DISABLED'],
]);

test('check derives the four display states from intent and observation', function (bool $local, ?string $meta, CallingControlState $expected) {
    $source = wcmSource(overrides: ['calling_enabled' => $local, 'calling_meta_status' => $meta]);

    expect(CallingControlState::for($source))->toBe($expected);
})->with([
    'off' => [false, null, CallingControlState::Off],
    'off, confirmed' => [false, 'DISABLED', CallingControlState::Off],
    'on' => [true, 'ENABLED', CallingControlState::On],
    'unverified' => [true, null, CallingControlState::Unverified],
    'mismatch: intent on, meta off' => [true, 'DISABLED', CallingControlState::Mismatch],
    'mismatch: intent off, meta on' => [false, 'ENABLED', CallingControlState::Mismatch],
]);

test('a failed check keeps the previous snapshot and shows a safe message', function (mixed $get, string $expected) {
    wcmFakeMeta(get: $get);
    $checkedAt = Carbon::now()->subHour();
    $source = wcmSource(overrides: ['calling_enabled' => true, 'calling_meta_status' => 'ENABLED', 'calling_meta_checked_at' => $checkedAt]);

    $result = wcmManager()->check(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'danger');
    $fresh = $source->fresh();
    expect($result->message)->toContain($expected)->not->toContain(WCM_META_TEXT)->not->toContain(WCM_TOKEN)
        ->and($fresh->calling_meta_status)->toBe('ENABLED')
        ->and($fresh->calling_meta_checked_at->equalTo($checkedAt))->toBeTrue()
        ->and($fresh->calling_enabled)->toBeTrue();
})->with([
    'token error' => [fn () => wcmMetaError(190, 401), 'access token'],
    'permission error' => [fn () => wcmMetaError(200, 403), 'permission'],
    'server error' => [fn () => wcmMetaError(138004, 500), 'temporary problem'],
    'timeout' => [new ConnectionException('cURL error 28 '.WCM_TOKEN), 'Could not reach'],
]);

test('a check with no reported status writes nothing', function () {
    wcmFakeMeta(get: Http::response(['messaging_product' => 'whatsapp']));
    $source = wcmSource();

    $result = wcmManager()->check(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'warning');
    expect($source->fresh()->calling_meta_status)->toBeNull()->and($source->fresh()->calling_meta_checked_at)->toBeNull();
});

test('check refuses a source without a phone number id or token, without any request', function () {
    $log = wcmFakeMeta();
    $source = wcmSource(config: ['access_token' => '']);

    wcmExpectVariant(wcmManager()->check(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id), 'danger');

    expect($log)->toHaveCount(0);
});

test('check surfaces restrictions and a SIP warning transiently and stores neither', function () {
    wcmFakeMeta(get: Http::response(['calling' => [
        'status' => 'ENABLED',
        'sip' => ['status' => 'ENABLED'],
        'restrictions' => [['type' => 'RESTRICTED_USER_INITIATED_CALLING', 'reason' => WCM_META_TEXT, 'expiration' => '1900000000']],
    ]]));
    $source = wcmSource(overrides: ['calling_enabled' => true]);
    $before = $source->fresh()->getAttributes();

    $result = wcmManager()->check(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    expect($result->restrictions)->toBe([['type' => 'RESTRICTED_USER_INITIATED_CALLING', 'expiration' => 1_900_000_000]])
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('SIP')
        ->and(json_encode($result))->not->toContain(WCM_META_TEXT);

    // Only the two observation columns (and updated_at) differ — no restriction storage.
    $after = $source->fresh()->getAttributes();
    $changed = array_keys(array_diff_assoc($after, $before));
    expect(array_diff($changed, ['calling_meta_status', 'calling_meta_checked_at', 'updated_at']))->toBe([]);
});

test('a slow older check cannot overwrite a newer observation', function () {
    $source = wcmSource(overrides: ['calling_enabled' => true]);
    $admin = PlatformUser::factory()->motherAdmin()->create();

    // Check A starts at 10:00:00; while its GET is in flight, Disable completes
    // at 10:00:05 and records DISABLED. A's later-arriving ENABLED is stale.
    wcmFakeMeta(
        post: Http::response(['success' => true]),
        get: function () use ($source, $admin) {
            Carbon::setTestNow(Carbon::now()->addSeconds(5));
            wcmManager()->disable($admin, $source->tenant, $source->id);

            return Http::response(['calling' => ['status' => 'ENABLED']]);
        },
    );

    Carbon::setTestNow('2026-09-21 10:00:00');
    wcmManager()->check($admin, $source->tenant, $source->id);

    $fresh = $source->fresh();
    expect($fresh->calling_meta_status)->toBe('DISABLED')
        ->and($fresh->calling_enabled)->toBeFalse()
        ->and($fresh->calling_meta_checked_at->equalTo(Carbon::parse('2026-09-21 10:00:05')))->toBeTrue();
});

// ── Disable ─────────────────────────────────────────────────────────────────

test('disable turns the local intent off and records DISABLED after Meta accepts it', function () {
    wcmFakeMeta();
    $source = wcmSource(overrides: ['calling_enabled' => true, 'calling_meta_status' => 'ENABLED', 'calling_meta_checked_at' => Carbon::now()->subHour()]);

    $result = wcmManager()->disable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'success');
    $fresh = $source->fresh();
    expect($fresh->calling_enabled)->toBeFalse()
        ->and($fresh->calling_meta_status)->toBe('DISABLED')
        ->and($fresh->calling_meta_checked_at->equalTo(Carbon::now()))->toBeTrue()
        ->and(CallingControlState::for($fresh))->toBe(CallingControlState::Off);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === ['calling' => ['status' => 'DISABLED']]);
});

test('disable keeps the local intent off and reports a mismatch when Meta fails', function (mixed $post) {
    $log = wcmFakeMeta(post: $post);
    $source = wcmSource(overrides: ['calling_enabled' => true, 'calling_meta_status' => 'ENABLED', 'calling_meta_checked_at' => Carbon::now()->subHour()]);

    $result = wcmManager()->disable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'warning');
    $fresh = $source->fresh();
    expect($result->message)->toContain('Calling is off here')->toContain('Run Check')->not->toContain(WCM_META_TEXT)->not->toContain(WCM_TOKEN)
        ->and($fresh->calling_enabled)->toBeFalse()
        ->and($fresh->calling_meta_status)->toBe('ENABLED')
        ->and($fresh->calling_meta_checked_at->equalTo(Carbon::now()->subHour()))->toBeTrue()
        ->and(CallingControlState::for($fresh))->toBe(CallingControlState::Mismatch)
        ->and($log->getArrayCopy())->toBe(['POST']);
})->with([
    'meta error' => [fn () => wcmMetaError(138004, 500)],
    'auth error' => [fn () => wcmMetaError(190, 401)],
    'timeout' => [new ConnectionException('cURL error 28 '.WCM_TOKEN)],
]);

test('a disable that timed out after Meta applied it is reconciled by the next check', function () {
    $source = wcmSource(overrides: ['calling_enabled' => true, 'calling_meta_status' => 'ENABLED', 'calling_meta_checked_at' => Carbon::now()->subHour()]);
    $admin = PlatformUser::factory()->motherAdmin()->create();

    wcmFakeMeta(post: new ConnectionException('timeout'));
    wcmManager()->disable($admin, $source->tenant, $source->id);
    expect(CallingControlState::for($source->fresh()))->toBe(CallingControlState::Mismatch);

    Carbon::setTestNow(Carbon::now()->addMinute());
    wcmFakeMeta(get: Http::response(['calling' => ['status' => 'DISABLED']]));
    wcmManager()->check($admin, $source->tenant, $source->id);

    expect(CallingControlState::for($source->fresh()))->toBe(CallingControlState::Off);
});

test('disable without credentials still turns the intent off locally and warns', function () {
    $log = wcmFakeMeta();
    $source = wcmSource(overrides: ['calling_enabled' => true], config: ['access_token' => '']);

    $result = wcmManager()->disable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    wcmExpectVariant($result, 'warning');
    expect($result->message)->toContain('WhatsApp could not be updated')
        ->and($source->fresh()->calling_enabled)->toBeFalse()
        ->and($log)->toHaveCount(0);
});

test('disabling twice is harmless and disable never re-enables', function () {
    wcmFakeMeta();
    $source = wcmSource(overrides: ['calling_enabled' => true]);
    $admin = PlatformUser::factory()->motherAdmin()->create();

    wcmManager()->disable($admin, $source->tenant, $source->id);
    wcmExpectVariant(wcmManager()->disable($admin, $source->tenant, $source->id), 'success');

    expect($source->fresh()->calling_enabled)->toBeFalse();
});

test('the local intent is off before the Meta request is even sent on disable', function () {
    $source = wcmSource(overrides: ['calling_enabled' => true]);
    $seenDuringRequest = null;

    wcmFakeMeta(post: function () use ($source, &$seenDuringRequest) {
        $seenDuringRequest = $source->fresh()->calling_enabled;

        return Http::response(['success' => true]);
    });

    wcmManager()->disable(PlatformUser::factory()->motherAdmin()->create(), $source->tenant, $source->id);

    expect($seenDuringRequest)->toBeFalse();
});

// ── Logging ─────────────────────────────────────────────────────────────────

test('admin actions log structured outcomes and never a token, secret, phone number, SDP or Meta text', function () {
    $logs = wcmCaptureLogs();
    $source = wcmSource();
    $admin = PlatformUser::factory()->motherAdmin()->create();

    wcmFakeMeta(post: wcmMetaError(190, 401));
    wcmManager()->enable($admin, $source->tenant, $source->id);
    wcmFakeMeta(get: Http::response(['calling' => ['status' => 'ENABLED']]));
    wcmManager()->check($admin, $source->tenant, $source->id);
    wcmFakeMeta(post: new ConnectionException('cURL error 28 '.WCM_TOKEN));
    wcmManager()->disable($admin, $source->tenant, $source->id);

    $records = collect($logs->getRecords())->filter(fn ($record): bool => $record->message === 'WA CALLING ADMIN')->values();
    expect($records)->toHaveCount(3);

    $first = $records[0]->context;
    expect($first)->toMatchArray([
        'tenant_id' => $source->tenant_id,
        'lead_source_id' => $source->id,
        'action' => 'enable',
        'outcome' => 'meta_rejected',
        'category' => 'authentication',
        'meta_code' => 190,
        'http_status' => 401,
        'fbtrace_id' => 'TRACE-ADM',
        'platform_user_id' => $admin->id,
    ])->and($first)->toHaveKey('duration_ms');

    $everything = json_encode(array_map(fn ($record): array => [$record->message, $record->context], $logs->getRecords()));
    expect($everything)
        ->not->toContain(WCM_TOKEN)
        ->not->toContain(Wa::APP_SECRET)
        ->not->toContain(WCM_META_TEXT)
        ->not->toContain(Wa::SDP_SENTINEL)
        ->not->toContain(Wa::CALLER)
        ->not->toContain('Bearer');
});
