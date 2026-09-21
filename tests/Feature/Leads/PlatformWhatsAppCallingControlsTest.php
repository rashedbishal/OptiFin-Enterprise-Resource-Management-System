<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\WhatsAppCallFixtures as Wa;

const WPC_TOKEN = 'EAAG-ui-sentinel-token-88de';

beforeEach(function () {
    config(['calling.enable_action_available' => true]);
});

function wpcSource(Tenant $tenant, array $overrides = [], array $config = []): LeadSource
{
    return Wa::source($tenant, array_merge(['calling_enabled' => false], $overrides), array_merge(['access_token' => WPC_TOKEN], $config));
}

function wpcComponent(Tenant $tenant, ?PlatformUser $admin = null): Testable
{
    // There can only ever be one mother admin, so reuse it across calls in a test.
    $admin ??= PlatformUser::query()->where('role_id', null)->first() ?? PlatformUser::factory()->motherAdmin()->create();

    return Livewire::actingAs($admin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('completeInitialLoad');
}

function wpcToastVariant(string $variant): Closure
{
    return fn ($name, $params): bool => $params['dataset']['variant'] === $variant;
}

// ── What renders ────────────────────────────────────────────────────────────

test('a WhatsApp row shows the calling controls and state, other source types do not', function () {
    $tenant = Tenant::factory()->create();
    wpcSource($tenant);

    wpcComponent($tenant)
        ->assertSee('Calling off')
        ->assertSee('Enable')
        ->assertSee('Disable')
        ->assertSee('Check');

    $other = Tenant::factory()->create();
    LeadSource::factory()->create([
        'tenant_id' => $other->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 't', 'verify_token' => 'v'],
    ]);

    wpcComponent($other)
        ->assertDontSee('Calling off')
        ->assertDontSee('Calling on')
        ->assertDontSee('Calling unverified');
});

test('the row shows the local state, Meta\'s observed state and the last check time', function () {
    $tenant = Tenant::factory()->create();
    wpcSource($tenant, ['calling_enabled' => true, 'calling_meta_status' => 'DISABLED', 'calling_meta_checked_at' => now()->subHours(3)]);

    wpcComponent($tenant)
        ->assertSee('Calling mismatch')
        ->assertSee('This app: on')
        ->assertSee('WhatsApp: disabled')
        ->assertSee('checked 3 hours ago');
});

test('while the rollout gate is closed Enable is unavailable and explains why, but Check and Disable remain', function () {
    config(['calling.enable_action_available' => false]);
    $tenant = Tenant::factory()->create();
    $source = wpcSource($tenant);

    $component = wpcComponent($tenant)
        ->assertSee('Calling is prepared but browser answering is not available yet')
        ->assertSeeHtml("wire:click=\"enableCalling('{$source->id}')\"")
        ->assertSeeHtml("wire:click=\"checkCalling('{$source->id}')\"")
        ->assertSeeHtml("wire:click=\"confirmDisableCalling('{$source->id}')\"");

    expect($component->html())->toMatch('/<button[^>]*enableCalling[^>]*disabled/s');
    expect($component->html())->not->toMatch('/<button[^>]*checkCalling[^>]*\sdisabled(?!:)/s');

    // And the server-side action refuses it too, so removing `disabled` in the browser gains nothing.
    Http::fake();
    $component->call('enableCalling', $source->id)
        ->assertDispatched('toast-show', wpcToastVariant('danger'));
    Http::assertNothingSent();
    expect($source->fresh()->calling_enabled)->toBeFalse();
});

test('Enable is unavailable with a stated reason for an inactive source or missing credentials', function (array $overrides, array $config, string $reason) {
    $tenant = Tenant::factory()->create();
    wpcSource($tenant, $overrides, $config);

    wpcComponent($tenant)->assertSee($reason);
})->with([
    'inactive' => [['is_active' => false], [], 'Activate this source before enabling calling.'],
    'no app secret' => [[], ['app_secret' => ''], 'Add the app secret to this source'],
    'no phone number id' => [[], ['phone_number_id' => ''], 'Add the phone number ID to this source'],
    'no access token' => [[], ['access_token' => ''], 'Add the access token to this source'],
]);

test('the existing row actions are unchanged', function () {
    $tenant = Tenant::factory()->create();
    $source = wpcSource($tenant);

    wpcComponent($tenant)
        ->assertSeeHtml("wire:click=\"edit('{$source->id}')\"")
        ->assertSeeHtml("wire:click=\"testConnection('{$source->id}')\"")
        ->assertSeeHtml("wire:click=\"toggleActive('{$source->id}')\"")
        ->assertSeeHtml("wire:click=\"confirmDelete('{$source->id}')\"");
});

// ── Actions ─────────────────────────────────────────────────────────────────

test('enabling from the page turns calling on and toasts', function () {
    Http::fake(fn (Request $request) => $request->method() === 'POST'
        ? Http::response(['success' => true])
        : Http::response(['calling' => ['status' => 'ENABLED']]));
    $tenant = Tenant::factory()->create();
    $source = wpcSource($tenant);

    wpcComponent($tenant)
        ->call('enableCalling', $source->id)
        ->assertDispatched('toast-show', wpcToastVariant('success'))
        ->assertSee('Calling on');

    expect($source->fresh()->calling_enabled)->toBeTrue();
});

test('checking from the page shows restrictions and warnings transiently', function () {
    Http::fake(['*' => Http::response(['calling' => [
        'status' => 'ENABLED',
        'sip' => ['status' => 'ENABLED'],
        'restrictions' => [['type' => 'RESTRICTED_USER_INITIATED_CALLING', 'expiration' => '1900000000']],
    ]])]);
    $tenant = Tenant::factory()->create();
    $source = wpcSource($tenant, ['calling_enabled' => true]);

    $component = wpcComponent($tenant)
        ->call('checkCalling', $source->id)
        ->assertSee('Customers are restricted from calling this number')
        ->assertSee('SIP calling is enabled for this number');

    expect($source->fresh()->calling_enabled)->toBeTrue()->and($source->fresh()->calling_meta_status)->toBe('ENABLED');

    // Not persisted: a fresh page load does not show them.
    wpcComponent($tenant)
        ->assertDontSee('Customers are restricted from calling this number')
        ->assertDontSee('SIP calling is enabled for this number');
});

test('a failed check from the page toasts a safe error and never the Meta text or token', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'RAW META WORDING '.WPC_TOKEN, 'code' => 190, 'fbtrace_id' => 'T']], 401)]);
    $tenant = Tenant::factory()->create();
    $source = wpcSource($tenant);

    $component = wpcComponent($tenant)
        ->call('checkCalling', $source->id)
        ->assertDispatched('toast-show', wpcToastVariant('danger'))
        ->assertDontSee('RAW META WORDING')
        ->assertDontSee(WPC_TOKEN);

    expect(json_encode($component->effects))->not->toContain(WPC_TOKEN)->not->toContain('RAW META WORDING');
});

test('disabling asks for confirmation first and then turns calling off', function () {
    Http::fake(['*' => Http::response(['success' => true])]);
    $tenant = Tenant::factory()->create();
    $source = wpcSource($tenant, ['calling_enabled' => true, 'calling_meta_status' => 'ENABLED', 'calling_meta_checked_at' => now()->subMinute()]);

    $component = wpcComponent($tenant)
        ->call('confirmDisableCalling', $source->id)
        ->assertSet('showDisableCallingModal', true)
        ->assertSet('confirmingDisableCallingId', $source->id);

    Http::assertNothingSent();
    expect($source->fresh()->calling_enabled)->toBeTrue();

    $component->call('disableCallingConfirmed')
        ->assertSet('showDisableCallingModal', false)
        ->assertSet('confirmingDisableCallingId', null)
        ->assertDispatched('toast-show', wpcToastVariant('success'));

    expect($source->fresh()->calling_enabled)->toBeFalse()->and($source->fresh()->calling_meta_status)->toBe('DISABLED');
});

test('a failed disable from the page still turns the local intent off and warns', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));
    $tenant = Tenant::factory()->create();
    $source = wpcSource($tenant, ['calling_enabled' => true, 'calling_meta_status' => 'ENABLED', 'calling_meta_checked_at' => now()->subMinute()]);

    wpcComponent($tenant)
        ->call('confirmDisableCalling', $source->id)
        ->call('disableCallingConfirmed')
        ->assertDispatched('toast-show', wpcToastVariant('warning'))
        ->assertSee('Calling mismatch');

    expect($source->fresh()->calling_enabled)->toBeFalse();
});

test('disableCallingConfirmed with nothing pending does nothing', function () {
    Http::fake();
    $tenant = Tenant::factory()->create();
    $source = wpcSource($tenant, ['calling_enabled' => true]);

    wpcComponent($tenant)->call('disableCallingConfirmed');

    Http::assertNothingSent();
    expect($source->fresh()->calling_enabled)->toBeTrue();
});

// ── Tenant isolation / tamper resistance ────────────────────────────────────

test('a browser-supplied source id from another tenant is not found for every calling action', function (string $method) {
    Http::fake();
    $tenant = Tenant::factory()->create();
    $foreign = wpcSource(Tenant::factory()->create(), ['calling_enabled' => true]);

    expect(fn () => wpcComponent($tenant)->call($method, $foreign->id))->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
    expect($foreign->fresh()->calling_enabled)->toBeTrue()->and($foreign->fresh()->calling_meta_checked_at)->toBeNull();
})->with(['enableCalling', 'checkCalling', 'confirmDisableCalling']);

test('a forged disable confirmation id from another tenant is not found and changes nothing', function () {
    Http::fake();
    $tenant = Tenant::factory()->create();
    $foreign = wpcSource(Tenant::factory()->create(), ['calling_enabled' => true]);

    expect(fn () => wpcComponent($tenant)
        ->set('confirmingDisableCallingId', $foreign->id)
        ->call('disableCallingConfirmed'))->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
    expect($foreign->fresh()->calling_enabled)->toBeTrue();
});

test('the transient calling details are locked against browser writes', function () {
    $tenant = Tenant::factory()->create();

    expect(fn () => wpcComponent($tenant)->set('callingDetails', ['x' => ['restrictions' => [], 'warnings' => ['<b>injected</b>']]]))
        ->toThrow(Exception::class, 'Cannot update locked property');
});

test('a platform user without client-management update permission cannot open the page that hosts the calling actions', function () {
    Http::fake();
    $tenant = Tenant::factory()->create();
    wpcSource($tenant);

    // mount() authorizes before any action can exist; the action-level
    // authorization is covered in ManageWhatsAppCallingTest.
    $this->actingAs(PlatformUser::factory()->create(), 'platform')
        ->get(route('admin.clients.lead-sources', $tenant))
        ->assertForbidden();

    Http::assertNothingSent();
});
