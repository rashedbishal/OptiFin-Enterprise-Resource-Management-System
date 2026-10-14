<?php

use App\Actions\Platform\DeleteCallPermissionRequestTemplate;
use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Platform Admin side — deletes the per-WABA call_permission_request
 * template from Meta so a replacement with different wording can be
 * created. No local catalog row (see CreateCallPermissionRequestTemplate's
 * own docblock), so this only ever talks to Meta.
 */

function dcprtSource(Tenant $tenant): LeadSource
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

test('a successful meta delete reports success', function () {
    $tenant = Tenant::factory()->create();
    $source = dcprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    $result = app(DeleteCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('success');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://graph.facebook.com/'.config('calling.graph_version').'/999/message_templates?access_token=waba-token&name='.config('calling.call_permission_request_template')['name']);
});

test('a meta rejection reports the real error', function () {
    $tenant = Tenant::factory()->create();
    $source = dcprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Some transient Meta error']], 400)]);

    $result = app(DeleteCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('danger');
    expect($result->message)->toBe('Some transient Meta error');
});

test('meta reporting the template as already gone is still treated as success', function () {
    $tenant = Tenant::factory()->create();
    $source = dcprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template does not exist.']], 400)]);

    $result = app(DeleteCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('success');
});

test('it refuses a non-whatsapp source without calling meta', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    $result = app(DeleteCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id);

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

    $result = app(DeleteCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('danger');
    Http::assertNothingSent();
});

test('a lead source belonging to another tenant is a 404, not a cross-tenant delete', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $source = dcprtSource($other);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    app(DeleteCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id);
})->throws(ModelNotFoundException::class);

test('the confirm modal flow deletes only after confirmation', function () {
    $tenant = Tenant::factory()->create();
    $source = dcprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('confirmDeleteCallPermissionTemplate', $source->id)
        ->assertSet('showDeleteCallPermissionTemplateModal', true)
        ->call('deleteCallPermissionTemplateConfirmed')
        ->assertSet('showDeleteCallPermissionTemplateModal', false)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success');
});
