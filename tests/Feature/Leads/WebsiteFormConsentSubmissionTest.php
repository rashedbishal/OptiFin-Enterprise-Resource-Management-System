<?php

use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormField;

/**
 * Mirrors WebsiteFormSubmissionTest.php's own createWebsiteFormWithToken()
 * helper exactly (Feature #13) — kept local to this file since Pest
 * functions aren't shared across files without an explicit require.
 */
function createConsentWebsiteFormWithToken(Tenant $tenant, string $plaintextToken, array $overrides = []): WebsiteForm
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

test('an explicitly granted optional privacy consent creates a LeadConsent and LeadConsentEvent', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-privacy');
    WebsiteFormField::factory()->consent(ConsentType::Privacy)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-privacy']), [
        'name' => 'Jane Doe',
        'privacy' => '1',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    $consent = LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Privacy)->first();

    expect($consent)->not->toBeNull();
    expect($consent->status)->toBe(ConsentStatus::Granted);
    expect($consent->source)->toBe(ConsentSource::WebsiteForm);
    expect($consent->website_form_id)->toBe($form->id);
    expect($consent->website_form_name)->toBe($form->name);
    expect(LeadConsentEvent::where('lead_id', $lead->id)->count())->toBe(1);
});

test('marketing consent is tracked independently of privacy consent on the same form', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-both');
    WebsiteFormField::factory()->consent(ConsentType::Privacy)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);
    WebsiteFormField::factory()->consent(ConsentType::Marketing)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id, 'sort_order' => 1]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-both']), [
        'name' => 'Jane Doe',
        'privacy' => '1',
        'marketing' => '0',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();

    expect(LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Privacy)->first()?->status)->toBe(ConsentStatus::Granted);
    // Explicit false on an OPTIONAL field is treated the same as omitted —
    // no record at all, still Not Provided, not a withdrawal.
    expect(LeadConsent::where('lead_id', $lead->id)->where('consent_type', ConsentType::Marketing)->exists())->toBeFalse();
});

test('an optional consent field that is omitted creates no consent record', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-optional');
    WebsiteFormField::factory()->consent(ConsentType::Privacy, required: false)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-optional']), [
        'name' => 'Jane Doe',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadConsent::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('a required consent field that is omitted rejects the submission with a 422 and creates no lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-required');
    WebsiteFormField::factory()->consent(ConsentType::Privacy, required: true)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-required']), [
        'name' => 'Jane Doe',
    ])->assertUnprocessable()->assertJsonValidationErrors(['privacy']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a required consent field that is explicitly false rejects the submission with a 422', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-required-false');
    WebsiteFormField::factory()->consent(ConsentType::Marketing, required: true)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-required-false']), [
        'name' => 'Jane Doe',
        'marketing' => '0',
    ])->assertUnprocessable()->assertJsonValidationErrors(['marketing']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('an invalid consent value rejects the submission with a 422', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-invalid');
    WebsiteFormField::factory()->consent(ConsentType::Privacy)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-invalid']), [
        'name' => 'Jane Doe',
        'privacy' => 'maybe',
    ])->assertUnprocessable()->assertJsonValidationErrors(['privacy']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('an unconfigured/forged consent field is silently discarded, never creating a consent record', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-forged');
    // No consent fields configured on this form at all.

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-forged']), [
        'name' => 'Jane Doe',
        'privacy' => '1',
        'marketing' => '1',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadConsent::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('a consent field configured on a different form of the same tenant is discarded, not cross-applied', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $formA = createConsentWebsiteFormWithToken($tenant, 'consent-token-a');
    $formB = createConsentWebsiteFormWithToken($tenant, 'consent-token-b');
    WebsiteFormField::factory()->consent(ConsentType::Marketing)->create(['tenant_id' => $tenant->id, 'website_form_id' => $formB->id]);
    // formA has NO consent fields configured.

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-a']), [
        'name' => 'Jane Doe',
        'marketing' => '1',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadConsent::where('lead_id', $lead->id)->exists())->toBeFalse();
});

test('policy version and url are snapshotted from the configured field onto the consent record', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-policy');
    WebsiteFormField::factory()->consent(ConsentType::Privacy)->create([
        'tenant_id' => $tenant->id,
        'website_form_id' => $form->id,
        'policy_version' => 'v2',
        'policy_url' => 'https://example.com/privacy',
    ]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-policy']), [
        'name' => 'Jane Doe',
        'privacy' => 'true',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    $consent = LeadConsent::where('lead_id', $lead->id)->first();

    expect($consent->policy_version)->toBe('v2');
    expect($consent->policy_url)->toBe('https://example.com/privacy');
});

test('IP address and user agent are captured on the resulting consent record', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-ip');
    WebsiteFormField::factory()->consent(ConsentType::Privacy)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(
        route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-ip']),
        ['name' => 'Jane Doe', 'privacy' => '1'],
        ['User-Agent' => 'TestAgent/1.0'],
    )->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    $consent = LeadConsent::where('lead_id', $lead->id)->first();

    expect($consent->ip_address)->not->toBeNull();
    expect($consent->user_agent)->toBe('TestAgent/1.0');
});

test('the honeypot short-circuits before any consent processing, creating no lead and no consent record', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-honeypot');
    WebsiteFormField::factory()->consent(ConsentType::Privacy, required: true)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-honeypot']), [
        'name' => 'Bot',
        '_gotcha' => 'i am a bot',
    ])->assertOk()->assertJson(['status' => 'ok']);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('an accepted true-ish value like "on" is treated as granted', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $form = createConsentWebsiteFormWithToken($tenant, 'consent-token-on');
    WebsiteFormField::factory()->consent(ConsentType::Privacy)->create(['tenant_id' => $tenant->id, 'website_form_id' => $form->id]);

    $this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'consent-token-on']), [
        'name' => 'Jane Doe',
        'privacy' => 'on',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadConsent::where('lead_id', $lead->id)->first()?->status)->toBe(ConsentStatus::Granted);
});
