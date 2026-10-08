<?php

use App\Actions\Platform\DeleteMessengerMessageTemplate;
use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\MessengerMessageTemplate;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;

/*
 * Platform Admin side — deletes the single existing Messenger template for
 * a source, both on Meta and in the local catalog, so a Platform Admin can
 * register a replacement afterward (see CreateMessengerMessageTemplate's
 * own one-per-source guard, and DeleteMessengerMessageTemplate's docblock
 * for why there is no "edit" instead).
 */

function dmmtSource(Tenant $tenant): LeadSource
{
    return LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'page-token'],
    ]);
}

test('a successful Meta delete removes the local catalog row too', function () {
    $tenant = Tenant::factory()->create();
    $source = dmmtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $template = MessengerMessageTemplate::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'name' => 'followup_after_24h']);

    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    $result = app(DeleteMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('success');
    expect(MessengerMessageTemplate::find($template->id))->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://graph.facebook.com/v26.0/1/message_templates?access_token=page-token&name=followup_after_24h');
});

test('a Meta rejection leaves the local catalog row untouched', function () {
    $tenant = Tenant::factory()->create();
    $source = dmmtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $template = MessengerMessageTemplate::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Some transient Meta error']], 400)]);

    $result = app(DeleteMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('danger');
    expect($result->message)->toBe('Some transient Meta error');
    expect(MessengerMessageTemplate::find($template->id))->not->toBeNull();
});

test('Meta reporting the template as already gone is still treated as success', function () {
    $tenant = Tenant::factory()->create();
    $source = dmmtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $template = MessengerMessageTemplate::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template does not exist.']], 400)]);

    $result = app(DeleteMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('success');
    expect(MessengerMessageTemplate::find($template->id))->toBeNull();
});

test('a source with no template to delete is rejected without calling Meta', function () {
    $tenant = Tenant::factory()->create();
    $source = dmmtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    $result = app(DeleteMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('danger');
    Http::assertNothingSent();
});

test('missing page credentials are rejected without calling Meta', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger, 'config' => []]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $template = MessengerMessageTemplate::factory()->create(['tenant_id' => $tenant->id, 'lead_source_id' => $source->id]);

    Http::fake();

    $result = app(DeleteMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id);

    expect($result->variant)->toBe('danger');
    Http::assertNothingSent();
    expect(MessengerMessageTemplate::find($template->id))->not->toBeNull();
});

test('a lead source belonging to another tenant is a 404, not a cross-tenant delete', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $source = dmmtSource($other);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    MessengerMessageTemplate::factory()->create(['tenant_id' => $other->id, 'lead_source_id' => $source->id]);

    Http::fake();

    app(DeleteMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id);
})->throws(ModelNotFoundException::class);
