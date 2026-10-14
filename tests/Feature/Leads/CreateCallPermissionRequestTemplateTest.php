<?php

use App\Actions\Platform\CreateCallPermissionRequestTemplate;
use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Platform Admin side — creates the one, specific WhatsApp message
 * template WhatsApp Outbound Calling's RequestLeadCallPermission depends
 * on existing and being Approved per-WABA. Confirmed live in production
 * (2026-10-14): a missing template is exactly what makes
 * RequestLeadCallPermission fail with Meta error 132001.
 */

function ccprtSource(Tenant $tenant): LeadSource
{
    return LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => [
            'phone_number_id' => '456',
            'access_token' => 'waba-token',
            'verify_token' => 'secret',
            'whatsapp_business_account_id' => '999',
        ],
    ]);
}

test('a successful create submits the exact configured template and reports Meta\'s status', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '888', 'status' => 'PENDING', 'category' => 'UTILITY'], 200)]);

    $result = app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('success');
    expect($result->message)->toContain('PENDING');

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/'.config('calling.graph_version').'/999/message_templates'
        && $request['name'] === config('calling.call_permission_request_template')['name']
        && $request['language'] === config('calling.call_permission_request_template')['language']
        && $request['category'] === 'UTILITY'
        && $request['access_token'] === 'waba-token'
        && $request['components'][0] === ['type' => 'BODY', 'text' => config('calling.call_permission_request_template')['body']]
        && $request['components'][1] === ['type' => 'FOOTER', 'text' => config('calling.call_permission_request_template')['footer']]
        && $request['components'][2] === ['type' => 'call_permission_request']);
});

test('a meta rejection surfaces the real error message, e.g. a footer over the character limit', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
        'message' => 'Invalid parameter',
        'error_user_title' => 'Character limit exceeded',
        'error_user_msg' => "The Footer field can't have more than 60 characters.",
    ]], 400)]);

    $result = app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('danger');
    expect($result->message)->toBe("The Footer field can't have more than 60 characters.");
});

test('it refuses a non-whatsapp source', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    $result = app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('danger');
    Http::assertNothingSent();
});

test('it refuses when the waba id is missing, without calling meta', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::Whatsapp,
        'config' => ['phone_number_id' => '456', 'access_token' => 'waba-token', 'verify_token' => 'secret'],
    ]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    $result = app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('danger');
    Http::assertNothingSent();
});

test('a platform admin without client-management update permission cannot create it', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $restrictedAdmin = PlatformUser::factory()->create();

    Http::fake();

    expect(fn () => app(CreateCallPermissionRequestTemplate::class)->handle($restrictedAdmin, $tenant, $source->id))
        ->toThrow(AuthorizationException::class);

    Http::assertNothingSent();
});

test('the button is wired from the lead sources page and shows a success toast', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '888', 'status' => 'PENDING'], 200)]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('createCallPermissionTemplate', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success');
});
