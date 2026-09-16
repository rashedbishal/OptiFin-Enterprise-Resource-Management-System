<?php

use App\Enums\LeadSourceType;
use App\Enums\WebsiteFormFixedField;
use App\Events\LeadReceived;
use App\Http\Controllers\Api\LeadWebhookController;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadCustomFieldValue;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormField;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// REL-2's idempotency-window tests use $this->travel() to simulate elapsed
// time without a real sleep. travel()/travelTo() only auto-reset Carbon's
// frozen test-now when called with a callback — a plain $this->travel(N)
// ->seconds() leaves it shifted for every later test in the same process
// otherwise. Resetting after EVERY test in this file (not just the ones
// that travel) guards against a failed assertion skipping an end-of-test
// travelBack() call too.
afterEach(function () {
    $this->travelBack();
});

/**
 * Creates (or reuses) the tenant's shared "Website Form" LeadSource and a
 * WebsiteForm pointed at it with a KNOWN plaintext token — mirrors exactly
 * what App\Livewire\Tenant\WebsiteForms\Index::save() does at creation time.
 */
function createWebsiteFormWithToken(Tenant $tenant, string $plaintextToken, array $overrides = []): WebsiteForm
{
    $source = LeadSource::query()->firstOrCreate(
        ['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm],
        ['name' => 'Website Form', 'is_active' => true],
    );

    return WebsiteForm::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'token_hash' => hash('sha256', $plaintextToken),
    ], $overrides));
}

test('a website form submission with a valid token creates a lead attributed to the shared Website Form source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createWebsiteFormWithToken($tenant, 'valid-token-123', ['name' => 'Contact Us']);
    WebsiteFormField::factory()->fixed(WebsiteFormFixedField::Email)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);
    WebsiteFormField::factory()->fixed(WebsiteFormFixedField::Message)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id, 'sort_order' => 1]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'valid-token-123']), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'message' => 'I would like a quote.',
    ])->assertOk()->assertJson(['status' => 'ok']);

    $lead = Lead::where('tenant_id', $tenant->id)->where('email', 'jane@example.com')->firstOrFail();
    expect($lead->name)->toBe('Jane Doe');
    expect($lead->message)->toBe('I would like a quote.');
    expect($lead->source_id)->toBe($form->lead_source_id);
    expect($lead->source_data['website_form_id'])->toBe($form->id);
    expect($lead->source_data['website_form_name'])->toBe('Contact Us');
});

test('an unknown token is rejected with a generic not-found response', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'not-a-real-token']), [
        'name' => 'Jane Doe',
    ])->assertNotFound();

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('an inactive website form does not accept submissions', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenant, 'inactive-token', ['is_active' => false]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'inactive-token']), [
        'name' => 'Jane Doe',
    ])->assertNotFound();

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('one tenant\'s form token cannot be used with a different tenant\'s tenant_code', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenantB, 'tenant-b-token');

    $this->postJson(route('api.webhook.website-form.submit', [$tenantA->tenant_code, 'tenant-b-token']), [
        'name' => 'Jane Doe',
    ])->assertNotFound();

    expect(Lead::where('tenant_id', $tenantA->id)->exists())->toBeFalse();
    expect(Lead::where('tenant_id', $tenantB->id)->exists())->toBeFalse();
});

test('a form cannot be submitted through using a different form\'s token from the same tenant', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $formA = createWebsiteFormWithToken($tenant, 'token-a');
    $formB = createWebsiteFormWithToken($tenant, 'token-b');
    WebsiteFormField::factory()->custom(
        LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Only On B', 'key' => Str::slug('Only On B'), 'type' => 'text'])->id
    )->create(['tenant_id' => $tenant->id, 'website_form_id' => $formB->id]);

    // Submitting formA's token with formB's field key: the key isn't part
    // of formA's own configuration, so it's simply discarded, not an error.
    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'token-a']), [
        'name' => 'Jane Doe',
        'only-on-b' => 'sneaky value',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('a fixed field not configured on the form is never stored even if submitted', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createWebsiteFormWithToken($tenant, 'token-x');
    // Only email is configured — phone is deliberately not.
    WebsiteFormField::factory()->fixed(WebsiteFormFixedField::Email)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'token-x']), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+8801712345678',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect($lead->email)->toBe('jane@example.com');
    expect($lead->phone)->toBeNull();
});

test('a required custom field blocks the submission when missing', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createWebsiteFormWithToken($tenant, 'token-required');
    $definition = LeadCustomFieldDefinition::factory()->required()->create(['tenant_id' => $tenant->id, 'name' => 'Budget', 'key' => 'budget', 'type' => 'text']);
    WebsiteFormField::factory()->custom($definition->id)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'token-required']), [
        'name' => 'Jane Doe',
    ])->assertUnprocessable()->assertJsonValidationErrors(['budget']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a valid custom field value is persisted via the existing custom field system', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createWebsiteFormWithToken($tenant, 'token-custom');
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source', 'key' => 'referral-source', 'type' => 'text']);
    WebsiteFormField::factory()->custom($definition->id)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'token-custom']), [
        'name' => 'Jane Doe',
        'referral-source' => 'Google',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->first()?->value)
        ->toBe('Google');
});

test('a custom field is validated against its actual type, rejecting the wrong shape of value', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createWebsiteFormWithToken($tenant, 'token-number');
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budget', 'key' => 'budget', 'type' => 'number']);
    WebsiteFormField::factory()->custom($definition->id)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'token-number']), [
        'name' => 'Jane Doe',
        'budget' => 'not-a-number',
    ])->assertUnprocessable();

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a deactivated custom field referenced by the form is skipped without failing the rest of the submission', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createWebsiteFormWithToken($tenant, 'token-deactivated');
    $definition = LeadCustomFieldDefinition::factory()->inactive()->create(['tenant_id' => $tenant->id, 'name' => 'Old Field', 'key' => 'old-field', 'type' => 'text']);
    WebsiteFormField::factory()->custom($definition->id)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'token-deactivated']), [
        'name' => 'Jane Doe',
        'old-field' => 'still sent by stale HTML',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('a filled honeypot field returns success but creates no lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenant, 'token-honeypot');

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'token-honeypot']), [
        'name' => 'Bot',
        '_gotcha' => 'i am a bot',
    ])->assertOk()->assertJson(['status' => 'ok']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a website form submission fires LeadReceived exactly once', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenant, 'token-event');

    Event::fake([LeadReceived::class]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'token-event']), [
        'name' => 'Jane Doe',
    ])->assertOk();

    Event::assertDispatchedTimes(LeadReceived::class, 1);
});

test('legacy api-key webhook and a builder-managed form coexist and attribute to the same source', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $legacySource = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'legacy-key-123'],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Legacy Visitor',
        'email' => 'legacy@example.com',
    ], ['X-Api-Key' => 'legacy-key-123'])->assertOk();

    $form = createWebsiteFormWithToken($tenant, 'builder-token-123');

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'builder-token-123']), [
        'name' => 'Builder Visitor',
    ])->assertOk();

    expect($form->lead_source_id)->toBe($legacySource->id);

    $legacyLead = Lead::where('tenant_id', $tenant->id)->where('email', 'legacy@example.com')->firstOrFail();
    $builderLead = Lead::where('tenant_id', $tenant->id)->where('name', 'Builder Visitor')->firstOrFail();
    expect($legacyLead->source_id)->toBe($legacySource->id);
    expect($builderLead->source_id)->toBe($legacySource->id);
});

test('the per-form rate limit blocks further submissions once exceeded', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenant, 'token-throttled');

    for ($i = 0; $i < 20; $i++) {
        $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'token-throttled']), [
            'name' => 'Visitor',
        ])->assertOk();
    }

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'token-throttled']), [
        'name' => 'One Too Many',
    ])->assertStatus(429);
});

test('the per-tenant rate limit blocks abuse spread across several forms, each staying under its own per-form limit', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    // 4 tokens, 4 separate forms — 15 requests each (60 total) stays under
    // every individual form's own 20/min ceiling, so only the tenant-wide
    // 60/min ceiling can be the one that trips. This is the exact scenario
    // the per-tenant limiter exists for (see AppServiceProvider::
    // configureRateLimiting()'s docblock): an attacker spreading requests
    // across a tenant's several forms from one IP.
    $tokens = ['tenant-limit-token-1', 'tenant-limit-token-2', 'tenant-limit-token-3', 'tenant-limit-token-4'];

    foreach ($tokens as $token) {
        createWebsiteFormWithToken($tenant, $token);
    }

    $responses = [];

    foreach ($tokens as $token) {
        for ($i = 0; $i < 15; $i++) {
            $responses[] = $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, $token]), [
                'name' => 'Visitor',
            ])->getStatusCode();
        }
    }

    // All 60 succeed — none of them, individually or cumulatively per-form,
    // exceeds any limiter yet.
    expect($responses)->toBe(array_fill(0, 60, 200));

    // The 61st request, on a brand-new 5th form (so it can't be the
    // per-form limiter — that form has had zero prior requests), is the
    // one that must trip the tenant-wide ceiling.
    createWebsiteFormWithToken($tenant, 'tenant-limit-token-5');

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'tenant-limit-token-5']), [
        'name' => 'One Too Many',
    ])->assertStatus(429);
});

// REL-2 — website-form create-time idempotency.

test('an exact immediate duplicate legacy submission creates only one lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'dup-key'],
    ]);

    $payload = ['name' => 'Dup Visitor', 'email' => 'dup@example.com', 'message' => 'hello'];

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), $payload, ['X-Api-Key' => 'dup-key'])->assertOk();
    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), $payload, ['X-Api-Key' => 'dup-key'])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('email', 'dup@example.com')->count())->toBe(1);
});

test('an exact immediate duplicate builder-form submission creates only one lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenant, 'dup-builder-token');

    $payload = ['name' => 'Dup Builder Visitor'];

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'dup-builder-token']), $payload)->assertOk();
    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'dup-builder-token']), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('name', 'Dup Builder Visitor')->count())->toBe(1);
});

test('a legacy retry a few seconds later, still within the idempotency window, does not create a second lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'window-key'],
    ]);

    $payload = ['name' => 'Window Visitor', 'email' => 'window@example.com'];

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), $payload, ['X-Api-Key' => 'window-key'])->assertOk();

    $this->travel(5)->seconds();

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), $payload, ['X-Api-Key' => 'window-key'])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('email', 'window@example.com')->count())->toBe(1);
});

test('a builder-form retry a few seconds later, still within the idempotency window, does not create a second lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenant, 'window-builder-token');

    $payload = ['name' => 'Window Builder Visitor'];

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'window-builder-token']), $payload)->assertOk();

    $this->travel(5)->seconds();

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'window-builder-token']), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('name', 'Window Builder Visitor')->count())->toBe(1);
});

test('the same submission content after the idempotency window has elapsed is accepted as a new lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenant, 'expired-window-token');

    $payload = ['name' => 'Later Visitor'];

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'expired-window-token']), $payload)->assertOk();

    // Past the 20s marker TTL — a deliberate later resubmission of the
    // exact same content must be treated as a new, legitimate submission.
    $this->travel(21)->seconds();

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'expired-window-token']), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('name', 'Later Visitor')->count())->toBe(2);
});

test('the same contact submitted to two different website forms is accepted both times', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $formA = createWebsiteFormWithToken($tenant, 'form-a-token');
    $formB = createWebsiteFormWithToken($tenant, 'form-b-token');
    expect($formA->lead_source_id)->toBe($formB->lead_source_id);

    $payload = ['name' => 'Cross Form Visitor'];

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'form-a-token']), $payload)->assertOk();
    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'form-b-token']), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('name', 'Cross Form Visitor')->count())->toBe(2);
});

test('the same contact submitted to two different tenants is accepted both times', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenantA, 'shared-token-a');
    createWebsiteFormWithToken($tenantB, 'shared-token-b');

    $payload = ['name' => 'Shared Visitor'];

    $this->postJson(route('api.webhook.website-form.submit', [$tenantA->tenant_code, 'shared-token-a']), $payload)->assertOk();
    $this->postJson(route('api.webhook.website-form.submit', [$tenantB->tenant_code, 'shared-token-b']), $payload)->assertOk();

    expect(Lead::where('tenant_id', $tenantA->id)->where('name', 'Shared Visitor')->count())->toBe(1);
    expect(Lead::where('tenant_id', $tenantB->id)->where('name', 'Shared Visitor')->count())->toBe(1);
});

test('two genuinely different payloads on the same website form are both accepted', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenant, 'distinct-payloads-token');

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'distinct-payloads-token']), ['name' => 'Visitor One'])->assertOk();
    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'distinct-payloads-token']), ['name' => 'Visitor Two'])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->whereIn('name', ['Visitor One', 'Visitor Two'])->count())->toBe(2);
});

test('a suppressed duplicate submission does not fire LeadReceived a second time', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    createWebsiteFormWithToken($tenant, 'event-once-token');

    $payload = ['name' => 'Event Visitor'];

    Event::fake([LeadReceived::class]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'event-once-token']), $payload)->assertOk();
    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'event-once-token']), $payload)->assertOk();

    Event::assertDispatchedTimes(LeadReceived::class, 1);
});

test('a suppressed duplicate submission produces no second lead row at all, so no duplicate per-lead side effect (assignment/scoring) can exist', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createWebsiteFormWithToken($tenant, 'no-side-effect-token');
    WebsiteFormField::factory()->fixed(WebsiteFormFixedField::Email)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $payload = ['name' => 'Side Effect Visitor', 'email' => 'sideeffect@example.com'];

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'no-side-effect-token']), $payload)->assertOk();
    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'no-side-effect-token']), $payload)->assertOk();

    $leads = Lead::where('tenant_id', $tenant->id)->where('email', 'sideeffect@example.com')->get();
    expect($leads)->toHaveCount(1);
});

test('a failed logical submission does not leave a false idempotency marker that blocks a successful retry', function () {
    $controller = new LeadWebhookController;
    $method = new ReflectionMethod($controller, 'withWebsiteFormIdempotency');
    $method->setAccessible(true);

    $content = ['name' => 'Reflection Visitor', 'email' => 'reflection@example.com'];

    expect(fn () => $method->invoke($controller, 'tenant-reflect', 'scope-reflect', $content, function (): never {
        throw new RuntimeException('simulated createLead() failure');
    }))->toThrow(RuntimeException::class, 'simulated createLead() failure');

    $secondCallRan = false;

    $method->invoke($controller, 'tenant-reflect', 'scope-reflect', $content, function () use (&$secondCallRan): null {
        $secondCallRan = true;

        return null;
    });

    expect($secondCallRan)->toBeTrue();
});

test('a website-form idempotency lock timeout fails open and still creates the lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'lock-timeout-key'],
    ]);

    $realCache = Cache::getFacadeRoot();

    Cache::shouldReceive('lock')->once()->andReturnUsing(function (string $name, int $seconds) use ($realCache): LockContract {
        if (str_contains($name, 'website-form-submission-processed:')) {
            $lock = Mockery::mock(LockContract::class);
            $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);

            return $lock;
        }

        return $realCache->lock($name, $seconds);
    });

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Lock Timeout Visitor',
        'email' => 'locktimeout@example.com',
    ], ['X-Api-Key' => 'lock-timeout-key'])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('email', 'locktimeout@example.com')->exists())->toBeTrue();
});
