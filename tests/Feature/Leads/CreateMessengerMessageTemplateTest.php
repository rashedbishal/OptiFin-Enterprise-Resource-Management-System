<?php

use App\Actions\Platform\CreateMessengerMessageTemplate;
use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\MessengerMessageTemplate;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

/*
 * Platform Admin side — creates a Messenger Utility Message Template
 * directly via Meta's Graph API and registers the matching
 * MessengerMessageTemplate catalog row in the same step, so the two can
 * never drift apart the way a manually-typed registration can (the exact
 * bug this app hit once already).
 */

function cmmtSource(Tenant $tenant): LeadSource
{
    return LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookMessenger,
        'config' => ['page_id' => '1', 'page_access_token' => 'page-token'],
    ]);
}

test('a successful create writes both the Meta template and the catalog row', function () {
    $tenant = Tenant::factory()->create();
    $source = cmmtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '999', 'status' => 'APPROVED', 'category' => 'UTILITY'], 200)]);

    $result = app(CreateMessengerMessageTemplate::class)->handle(
        $motherAdmin,
        $tenant,
        $source->id,
        'followup_after_24h',
        'en_US',
        'Hi {{1}}, following up on your inquiry about {{2}}.',
    );

    expect($result->variant)->toBe('success');

    Http::assertSent(fn ($request) => $request['name'] === 'followup_after_24h'
        && $request['language'] === 'en_US'
        && $request['category'] === 'UTILITY'
        && $request['components'][0]['text'] === 'Hi {{1}}, following up on your inquiry about {{2}}.'
        && count($request['components'][0]['example']['body_text'][0]) === 2);

    $template = MessengerMessageTemplate::where('tenant_id', $tenant->id)->firstOrFail();
    expect($template->lead_source_id)->toBe($source->id);
    expect($template->name)->toBe('followup_after_24h');
    expect($template->language)->toBe('en_US');
    expect($template->variable_count)->toBe(2);
    expect($template->is_active)->toBeTrue();
    expect($template->body_text)->toBe('Hi {{1}}, following up on your inquiry about {{2}}.');
});

test('a zero-variable body never sends an example field', function () {
    $tenant = Tenant::factory()->create();
    $source = cmmtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '999', 'status' => 'APPROVED'], 200)]);

    app(CreateMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'simple_notice', 'en_US', 'Thanks for reaching out.');

    Http::assertSent(fn ($request) => ! array_key_exists('example', $request['components'][0]));

    expect(MessengerMessageTemplate::where('tenant_id', $tenant->id)->firstOrFail()->variable_count)->toBe(0);
});

test('a platform rejection leaves no catalog row behind', function () {
    $tenant = Tenant::factory()->create();
    $source = cmmtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Duplicate template name']], 400)]);

    $result = app(CreateMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'followup_after_24h', 'en_US', 'Hi {{1}}.');

    expect($result->variant)->toBe('danger');
    expect($result->message)->toBe('Duplicate template name');
    expect(MessengerMessageTemplate::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a local duplicate is blocked before ever calling Meta', function () {
    $tenant = Tenant::factory()->create();
    $source = cmmtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    MessengerMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'name' => 'followup_after_24h', 'language' => 'en_US',
    ]);

    Http::fake();

    $result = app(CreateMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'followup_after_24h', 'en_US', 'Hi {{1}}.');

    expect($result->variant)->toBe('danger');
    Http::assertNothingSent();
});

test('a source with an existing template blocks a second one even under a totally different name and language', function () {
    $tenant = Tenant::factory()->create();
    $source = cmmtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    MessengerMessageTemplate::factory()->create([
        'tenant_id' => $tenant->id, 'lead_source_id' => $source->id, 'name' => 'order_update', 'language' => 'en',
    ]);

    Http::fake();

    $result = app(CreateMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'completely_different_name', 'es', 'Hola {{1}}.');

    expect($result->variant)->toBe('danger');
    Http::assertNothingSent();
    expect(MessengerMessageTemplate::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a non-messenger source is rejected', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    $result = app(CreateMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'followup_after_24h', 'en_US', 'Hi {{1}}.');

    expect($result->variant)->toBe('danger');
    Http::assertNothingSent();
    expect(MessengerMessageTemplate::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('missing page credentials are rejected without calling Meta', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger, 'config' => []]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    $result = app(CreateMessengerMessageTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'followup_after_24h', 'en_US', 'Hi {{1}}.');

    expect($result->variant)->toBe('danger');
    Http::assertNothingSent();
});
