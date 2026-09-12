<?php

use App\Enums\LeadSourceType;
use App\Enums\WebsiteFormFixedField;
use App\Events\LeadReceived;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadCustomFieldValue;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormField;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

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
