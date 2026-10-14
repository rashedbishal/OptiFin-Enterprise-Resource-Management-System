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
 *
 * name/language are fixed (config('calling.call_permission_request_template'))
 * and never admin-provided — RequestLeadCallPermission::sendTemplate()
 * hardcodes that exact name/language when sending later. body/footer ARE
 * admin-provided via a form (confirmCreateCallPermissionTemplate() opens
 * it, pre-filled from the same config's body/footer defaults;
 * saveCallPermissionTemplate() validates before calling this action), so
 * the Action itself just takes bodyText/footerText as plain strings.
 *
 * The body must be STATIC (no {{n}} placeholder at all) — flipped
 * 2026-10-14 after a real Meta content review REJECTED a personalized
 * version of this exact template while an identical-except-static one
 * was APPROVED on its first submission. See
 * App\Actions\Tenant\RequestLeadCallPermission's own docblock.
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

test('a successful create submits the given static body/footer under the fixed name/language and reports Meta\'s status', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '888', 'status' => 'PENDING', 'category' => 'UTILITY'], 200)]);

    $result = app(CreateCallPermissionRequestTemplate::class)->handle(
        $motherAdmin,
        $tenant,
        $source->id,
        'Custom static body.',
        'Custom footer.',
    );

    expect($result->variant)->toBe('success');
    expect($result->message)->toContain('PENDING');
    expect($result->message)->toContain(config('calling.call_permission_request_template')['name']);

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/'.config('calling.graph_version').'/999/message_templates'
        && $request['name'] === config('calling.call_permission_request_template')['name']
        && $request['language'] === config('calling.call_permission_request_template')['language']
        && $request['category'] === 'UTILITY'
        && $request['access_token'] === 'waba-token'
        && $request['components'][0] === ['type' => 'BODY', 'text' => 'Custom static body.']
        && $request['components'][1] === ['type' => 'FOOTER', 'text' => 'Custom footer.']
        && $request['components'][2] === ['type' => 'call_permission_request']);

    // The name just submitted is persisted onto this source's own config
    // — the first write for a source that's never had Create called
    // before (falls back to the global default until then).
    expect($source->fresh()->config[CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY])
        ->toBe(config('calling.call_permission_request_template')['name']);
});

test('a source with its own stored name uses that instead of the global default', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $source->update(['config' => array_merge($source->config, [CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY => 'call_permission_request_v5'])]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '888', 'status' => 'PENDING'], 200)]);

    app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'Body.', 'Footer.');

    Http::assertSent(fn ($request) => $request['name'] === 'call_permission_request_v5');
});

test('two sources each keep their own stored name independently', function () {
    $tenant = Tenant::factory()->create();
    $sourceA = ccprtSource($tenant);
    $sourceB = ccprtSource($tenant);
    $sourceA->update(['config' => array_merge($sourceA->config, [CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY => 'call_permission_request_v3'])]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '888', 'status' => 'PENDING'], 200)]);

    app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $sourceB->id, 'Body.', 'Footer.');

    // Creating B's template must never touch A's independently-stored name.
    expect($sourceA->fresh()->config[CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY])->toBe('call_permission_request_v3');
    expect($sourceB->fresh()->config[CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY])->toBe(config('calling.call_permission_request_template')['name']);
});

test('a meta rejection at the HTTP level surfaces the real error message, e.g. a footer over the character limit', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
        'message' => 'Invalid parameter',
        'error_user_title' => 'Character limit exceeded',
        'error_user_msg' => "The Footer field can't have more than 60 characters.",
    ]], 400)]);

    $result = app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'Body.', 'Footer.');

    expect($result->variant)->toBe('danger');
    expect($result->message)->toBe("The Footer field can't have more than 60 characters.");
});

test('meta reporting an immediate, synchronous REJECTED status is treated as a failure, not success', function () {
    // Reproduces a real production bug and a real production event on the
    // same day (2026-10-14): a 2xx HTTP response with status REJECTED in
    // the body used to be reported as "success" here — misleading, since
    // the template was never actually approved. This also confirms the
    // name gets bumped and the rejected object gets cleaned up, exactly
    // like a real Delete would, so the next Create attempt isn't stuck.
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake([
        'graph.facebook.com/*/message_templates' => Http::sequence()
            ->push(['id' => '777', 'status' => 'REJECTED', 'category' => 'UTILITY'])
            ->push(['success' => true]),
        'graph.facebook.com/*/777*' => Http::response(['rejected_reason' => 'INVALID_FORMAT']),
    ]);

    $result = app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'Body.', 'Footer.');

    expect($result->variant)->toBe('danger');
    expect($result->message)->toContain('INVALID_FORMAT');

    $expectedNext = CreateCallPermissionRequestTemplate::nextTemplateName(config('calling.call_permission_request_template')['name']);
    expect($result->message)->toContain($expectedNext);
    expect($source->fresh()->config[CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY])->toBe($expectedNext);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

test('a rejected-reason lookup that itself fails still reports the rejection, with no reason given', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake([
        'graph.facebook.com/*/message_templates' => Http::sequence()
            ->push(['id' => '777', 'status' => 'REJECTED'])
            ->push(['success' => true]),
        'graph.facebook.com/*/777*' => Http::response(['error' => ['message' => 'does not exist']], 404),
    ]);

    $result = app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'Body.', 'Footer.');

    expect($result->variant)->toBe('danger');
    expect($result->message)->toContain('no reason given');
});

test('it refuses a non-whatsapp source', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::FacebookMessenger]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    $result = app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'Body.', 'Footer.');

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

    $result = app(CreateCallPermissionRequestTemplate::class)->handle($motherAdmin, $tenant, $source->id, 'Body.', 'Footer.');

    expect($result->variant)->toBe('danger');
    Http::assertNothingSent();
});

test('a platform admin without client-management update permission cannot create it', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $restrictedAdmin = PlatformUser::factory()->create();

    Http::fake();

    expect(fn () => app(CreateCallPermissionRequestTemplate::class)->handle($restrictedAdmin, $tenant, $source->id, 'Body.', 'Footer.'))
        ->toThrow(AuthorizationException::class);

    Http::assertNothingSent();
});

test('the form opens pre-filled with the configured defaults', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('confirmCreateCallPermissionTemplate', $source->id)
        ->assertSet('showCreateCallPermissionTemplateModal', true)
        ->assertSet('creatingCallPermissionTemplateName', config('calling.call_permission_request_template')['name'])
        ->assertSet('callPermissionTemplateBody', config('calling.call_permission_request_template')['body'])
        ->assertSet('callPermissionTemplateFooter', config('calling.call_permission_request_template')['footer']);
});

test('the form and the row both show this source\'s own stored name, not the global default', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $source->update(['config' => array_merge($source->config, [CreateCallPermissionRequestTemplate::TEMPLATE_NAME_CONFIG_KEY => 'call_permission_request_v7'])]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('completeInitialLoad')
        ->assertSee('call_permission_request_v7')
        ->call('confirmCreateCallPermissionTemplate', $source->id)
        ->assertSet('creatingCallPermissionTemplateName', 'call_permission_request_v7');
});

test('submitting a body with a {{1}} placeholder is rejected without calling meta', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('confirmCreateCallPermissionTemplate', $source->id)
        ->set('callPermissionTemplateBody', 'Hi {{1}}, we would like to call you.')
        ->call('saveCallPermissionTemplate')
        ->assertHasErrors(['callPermissionTemplateBody']);

    Http::assertNothingSent();
});

test('submitting a body with any other {{n}} placeholder is also rejected without calling meta', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('confirmCreateCallPermissionTemplate', $source->id)
        ->set('callPermissionTemplateBody', 'Some {{3}} placeholder here.')
        ->call('saveCallPermissionTemplate')
        ->assertHasErrors(['callPermissionTemplateBody']);

    Http::assertNothingSent();
});

test('submitting a footer over 60 characters is rejected without calling meta', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('confirmCreateCallPermissionTemplate', $source->id)
        ->set('callPermissionTemplateFooter', str_repeat('a', 61))
        ->call('saveCallPermissionTemplate')
        ->assertHasErrors(['callPermissionTemplateFooter']);

    Http::assertNothingSent();
});

test('a valid static body/footer is submitted and shows a success toast, closing the modal', function () {
    $tenant = Tenant::factory()->create();
    $source = ccprtSource($tenant);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '888', 'status' => 'PENDING'], 200)]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('confirmCreateCallPermissionTemplate', $source->id)
        ->set('callPermissionTemplateBody', 'May we call you about your inquiry?')
        ->set('callPermissionTemplateFooter', 'Change this anytime.')
        ->call('saveCallPermissionTemplate')
        ->assertHasNoErrors()
        ->assertSet('showCreateCallPermissionTemplateModal', false)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success');

    Http::assertSent(fn ($request) => $request['components'][0]['text'] === 'May we call you about your inquiry?'
        && $request['components'][1]['text'] === 'Change this anytime.');
});
