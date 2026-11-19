<?php

use App\Actions\Tenant\IssueCallingAppPairingCode;
use App\Enums\CallStatus;
use App\Enums\TenantStatus;
use App\Livewire\Settings\CallingApp;
use App\Livewire\Tenant\Users\Index as UsersIndex;
use App\Models\CallingAppDevice;
use App\Models\Role;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\Support\LeadCallScenario as Lc;
use Tests\Support\WhatsAppCallFixtures as Wa;

/*
 * WhatsApp calling companion app — one-time pairing from the logged-in
 * browser, the device-token API that reuses the browser's own call
 * controllers, and unpairing (by the user, or by their Client Admin).
 */

function capIssueCode(TenantUser $user): string
{
    return app(IssueCallingAppPairingCode::class)->handle($user)['code'];
}

/**
 * @return array{token: string, device: CallingAppDevice}
 */
function capPair(TenantUser $user, string $deviceName = 'Pixel 8'): array
{
    $token = test()->postJson(route('api.calling-app.pair'), [
        'code' => capIssueCode($user),
        'device_name' => $deviceName,
    ])->assertOk()->json('data.token');

    return ['token' => $token, 'device' => CallingAppDevice::query()->where('tenant_user_id', $user->id)->firstOrFail()];
}

function capInvalidCodeError(): array
{
    return ['error' => [
        'code' => 'invalid_pairing_code',
        'message' => 'This pairing link has expired or was already used. Tap "Connect calling app" again.',
        'retryable' => false,
    ]];
}

// ── Pairing ─────────────────────────────────────────────────────────────────

test('the issued deep link carries a code that pairs the phone and returns a calling-only device token', function () {
    $x = Lc::make();

    $issued = app(IssueCallingAppPairingCode::class)->handle($x->agent);

    expect($issued['deep_link'])->toBe('optifin-calling://pair?code='.$issued['code']);

    $response = $this->postJson(route('api.calling-app.pair'), ['code' => $issued['code'], 'device_name' => 'Pixel 8']);

    $response->assertOk()->assertJsonPath('data.user', [
        'id' => $x->agent->id,
        'name' => $x->agent->name,
        'email' => $x->agent->email,
        'tenant' => $x->tenant->name,
    ]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $device = CallingAppDevice::query()->where('tenant_user_id', $x->agent->id)->sole();
    expect($device->tenant_id)->toBe($x->tenant->id)
        ->and($device->device_name)->toBe('Pixel 8')
        ->and($device->token->abilities)->toBe(['calling-app']);

    $this->withToken($response->json('data.token'))->getJson(route('api.calling-app.me'))
        ->assertOk()
        ->assertJsonPath('data.user.id', $x->agent->id);
});

test('a pairing code works only once', function () {
    $x = Lc::make();
    $code = capIssueCode($x->agent);

    $this->postJson(route('api.calling-app.pair'), ['code' => $code])->assertOk();

    $this->postJson(route('api.calling-app.pair'), ['code' => $code])
        ->assertStatus(422)
        ->assertExactJson(capInvalidCodeError());
    expect(PersonalAccessToken::query()->count())->toBe(1);
});

test('an expired pairing code is refused with 422', function () {
    $x = Lc::make();
    $code = capIssueCode($x->agent);

    $this->travel(config('calling.calling_app.pairing_code_ttl_seconds') + 1)->seconds();

    $this->postJson(route('api.calling-app.pair'), ['code' => $code])
        ->assertStatus(422)
        ->assertExactJson(capInvalidCodeError());
    expect(CallingAppDevice::query()->count())->toBe(0);
});

test('an unknown pairing code is refused with 422', function () {
    $this->postJson(route('api.calling-app.pair'), ['code' => 'not-a-real-code'])
        ->assertStatus(422)
        ->assertExactJson(capInvalidCodeError());
});

test('pairing without a code fails validation with 422', function () {
    $this->postJson(route('api.calling-app.pair'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code' => 'The code field is required.']);
});

test('a code issued before the user was deactivated can no longer pair', function () {
    $x = Lc::make();
    $code = capIssueCode($x->agent);

    $x->agent->update(['is_active' => false]);

    $this->postJson(route('api.calling-app.pair'), ['code' => $code])
        ->assertStatus(422)
        ->assertExactJson(capInvalidCodeError());
    expect(CallingAppDevice::query()->count())->toBe(0);
});

test('pairing a second phone unpairs the first one', function () {
    $x = Lc::make();
    $first = capPair($x->agent, 'Old phone');

    $second = capPair($x->agent, 'New phone');

    expect(CallingAppDevice::query()->where('tenant_user_id', $x->agent->id)->pluck('device_name')->all())->toBe(['New phone'])
        ->and(PersonalAccessToken::query()->whereKey($first['device']->personal_access_token_id)->exists())->toBeFalse();

    $this->withToken($first['token'])->getJson(route('api.calling-app.me'))->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($second['token'])->getJson(route('api.calling-app.me'))->assertOk();
});

// ── The device-token API ────────────────────────────────────────────────────

test('a request without a token is refused with 401', function () {
    $this->getJson(route('api.calling-app.me'))->assertUnauthorized();
});

test('a token without the calling-app ability is refused with 401', function () {
    $x = Lc::make();
    $token = $x->agent->createToken('something else')->plainTextToken;

    $this->withToken($token)->getJson(route('api.calling-app.me'))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unpaired');
});

test('a deactivated user is refused with 403', function () {
    $x = Lc::make();
    ['token' => $token] = capPair($x->agent);

    $x->agent->update(['is_active' => false]);

    $this->withToken($token)->getJson(route('api.calling-app.me'))
        ->assertForbidden()
        ->assertExactJson(['error' => [
            'code' => 'account_inactive',
            'message' => 'Your account or organization has been deactivated.',
            'retryable' => false,
        ]]);
});

test('a user of a suspended tenant is refused with 403', function () {
    $x = Lc::make();
    ['token' => $token] = capPair($x->agent);

    $x->tenant->update(['status' => TenantStatus::Suspended]);

    $this->withToken($token)->getJson(route('api.calling-app.me'))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'account_inactive');
});

test('a user whose role lacks the lead-management module is refused with 403', function () {
    $x = Lc::make();
    ['token' => $token] = capPair($x->agent);

    $bareRole = Role::create(['tenant_id' => $x->tenant->id, 'name' => 'No modules', 'slug' => 'no-modules']);
    $x->agent->update(['role_id' => $bareRole->id]);

    $this->withToken($token)->getJson(route('api.calling-app.me'))->assertForbidden();
});

test('the app polls active calls through the browser softphone\'s own endpoint, which also marks the user available', function () {
    $x = Lc::make();
    ['token' => $token] = capPair($x->agent);

    $this->withToken($token)->getJson(route('api.calling-app.calls.active'))
        ->assertOk()
        ->assertJsonPath('data.calls.0.id', $x->call->id);

    expect($x->agent->fresh()->softphone_last_seen_at)->not->toBeNull();
});

test('the app claims a call with the same result the browser gets, and only the claimant wins', function () {
    $x = Lc::make();
    ['token' => $agentToken] = capPair($x->agent);
    ['token' => $otherToken] = capPair($x->other);

    $this->withToken($agentToken)->postJson(route('api.calling-app.calls.claim', $x->call->id))
        ->assertOk()
        ->assertExactJson(['data' => [
            'call' => ['id' => $x->call->id, 'status' => 'answering', 'claimed_by_me' => true],
            'session' => ['sdp_type' => 'offer', 'sdp' => Wa::sdp()],
        ]]);

    app('auth')->forgetGuards();

    $this->withToken($otherToken)->postJson(route('api.calling-app.calls.claim', $x->call->id))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_claimed');
    expect($x->reload()->call->claimed_by)->toBe($x->agent->id);
});

test('a paired user not routed for a call cannot act on it', function () {
    $x = Lc::make();
    $outsider = clientUserWithLeadPermission($x->tenant, ['view', 'update']);
    ['token' => $token] = capPair($outsider);

    $this->withToken($token)->postJson(route('api.calling-app.calls.claim', $x->call->id))->assertForbidden();
    expect($x->reload()->call->status)->toBe(CallStatus::Ringing);
});

test('a paired user cannot reach another tenant\'s call', function () {
    $x = Lc::make();
    $y = Lc::make();
    ['token' => $token] = capPair($x->agent);

    $this->withToken($token)->postJson(route('api.calling-app.calls.claim', $y->call->id))->assertNotFound();
    expect($y->reload()->call->status)->toBe(CallStatus::Ringing);
});

test('the app unpairs itself, after which its token stops working', function () {
    $x = Lc::make();
    ['token' => $token] = capPair($x->agent);

    $this->withToken($token)->postJson(route('api.calling-app.unpair'))->assertOk();

    expect(CallingAppDevice::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson(route('api.calling-app.me'))->assertUnauthorized();
});

// ── Settings → Calling app ──────────────────────────────────────────────────

test('connecting from settings issues a code and opens the app\'s deep link', function () {
    $x = Lc::make();

    $component = Livewire::actingAs($x->agent)->test(CallingApp::class)->call('connect');

    $link = $component->get('pairingLink');
    expect($link)->toStartWith('optifin-calling://pair?code=');
    $component->assertDispatched('calling-app-open', link: $link);

    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
    $this->postJson(route('api.calling-app.pair'), ['code' => $query['code']])->assertOk();
});

test('a user unpairs their own phone from settings', function () {
    $x = Lc::make();
    capPair($x->agent);

    Livewire::actingAs($x->agent)->test(CallingApp::class)
        ->assertSee('Pixel 8')
        ->call('unpair');

    expect(CallingAppDevice::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('the calling app settings page requires the lead-management module', function () {
    $x = Lc::make();
    $bareRole = Role::create(['tenant_id' => $x->tenant->id, 'name' => 'No modules', 'slug' => 'no-modules']);
    $x->agent->update(['role_id' => $bareRole->id]);

    $this->actingAs($x->agent->fresh())->get(route('calling-app.edit'))->assertForbidden();
});

// ── Users page: Client Admin unpairs someone else's phone ───────────────────

test('a client admin unpairs another user\'s phone from the users page', function () {
    $x = Lc::make();
    capPair($x->agent);

    Livewire::actingAs($x->admin)->test(UsersIndex::class)
        ->call('confirmUnpairCallingApp', $x->agent->id)
        ->call('unpairCallingAppConfirmed');

    expect(CallingAppDevice::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('a client admin cannot unpair a phone in another tenant', function () {
    $x = Lc::make();
    $y = Lc::make();
    capPair($y->agent);

    expect(fn () => Livewire::actingAs($x->admin)->test(UsersIndex::class)
        ->call('confirmUnpairCallingApp', $y->agent->id)
        ->call('unpairCallingAppConfirmed'))->toThrow(ModelNotFoundException::class);

    expect(CallingAppDevice::query()->where('tenant_user_id', $y->agent->id)->exists())->toBeTrue();
});

// ── Browser → app hand-off ──────────────────────────────────────────────────

test('assetlinks.json publicly links the calling app\'s package and signing key to this site', function () {
    config([
        'calling.calling_app.android_package' => 'com.optifin.calling',
        'calling.calling_app.android_sha256_fingerprints' => ['AA:BB:CC'],
    ]);

    $this->getJson('/.well-known/assetlinks.json')
        ->assertOk()
        ->assertExactJson([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => 'com.optifin.calling',
                'sha256_cert_fingerprints' => ['AA:BB:CC'],
            ],
        ]]);
});

test('assetlinks.json advertises no app when no fingerprint is configured', function () {
    config(['calling.calling_app.android_sha256_fingerprints' => []]);

    $this->getJson('/.well-known/assetlinks.json')->assertOk()->assertExactJson([]);
});

test('the softphone is told whether this user has a paired phone', function () {
    $x = Lc::make();
    capPair($x->agent);

    $paired = $this->actingAs($x->agent)->get(route('dashboard'))->getContent();
    $unpaired = $this->actingAs($x->other)->get(route('dashboard'))->getContent();

    expect($paired)->toMatch('~paired\\\\u0022:true~')
        ->and($unpaired)->toMatch('~paired\\\\u0022:false~');
});
