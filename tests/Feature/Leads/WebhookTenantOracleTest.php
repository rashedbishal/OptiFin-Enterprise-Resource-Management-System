<?php

use App\Enums\LeadSourceType;
use App\Enums\TenantStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\WebsiteForm;
use Illuminate\Testing\TestResponse;

/**
 * Every public webhook resolver answers "this tenant code / source / form does
 * not resolve" with one and the same plain 404 — never a model-specific message
 * ("No query results for model [App\Models\Tenant]") that would tell an anonymous
 * caller which part of the tenant → source/form chain exists (the tenant_code
 * enumeration oracle). Valid requests, signature and token checks, and
 * throttling are untouched: only the failure to RESOLVE is uniform. (Status-
 * level differences that come from AUTHENTICATING a resolved source — a wrong
 * verify token's 403, a wrong API key's 401, a bad Meta signature's 403 — are
 * the credentials' own contract, not resolution, and stay as they are.)
 */

// Production error rendering: with APP_DEBUG on, Laravel appends exception class, file, line and
// trace to every error body (which differ per call site by design), masking what an anonymous
// caller sees in a real deployment.
beforeEach(fn () => config(['app.debug' => false]));

/**
 * @return array{tenant: Tenant, suspended: Tenant, other: Tenant}
 */
function oracleTenants(): array
{
    ['tenant' => $tenant] = tenantWithLeadManagement();
    ['tenant' => $suspended] = tenantWithLeadManagement();
    ['tenant' => $other] = tenantWithLeadManagement();
    $suspended->update(['status' => TenantStatus::Suspended]);

    return compact('tenant', 'suspended', 'other');
}

function oracleSource(Tenant $tenant, LeadSourceType $type, array $config = [], bool $active = true): LeadSource
{
    return LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => $type,
        'is_active' => $active,
        'config' => $config + ['verify_token' => 'secret-token', 'api_key' => 'test-key-123', 'page_id' => '123'],
    ]);
}

function oracleForm(Tenant $tenant, string $token, bool $active = true): WebsiteForm
{
    $source = LeadSource::query()->firstOrCreate(
        ['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm],
        ['name' => 'Website Form', 'is_active' => true],
    );

    return WebsiteForm::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_source_id' => $source->id,
        'token_hash' => hash('sha256', $token),
        'is_active' => $active,
    ]);
}

/**
 * @return array{int, string}
 */
function oracleShape(TestResponse $response): array
{
    return [$response->getStatusCode(), $response->getContent()];
}

/**
 * @param  array<string, array{int, string}>  $shapes
 */
function expectOneUniformNotFound(array $shapes): void
{
    expect($shapes)->not->toBeEmpty();

    foreach ($shapes as $label => [$status, $body]) {
        expect($status)->toBe(404, $label);

        foreach (['App\\\\Models', 'App\\Models', 'No query results', 'Tenant', 'LeadSource', 'WebsiteForm', 'model'] as $leak) {
            expect($body)->not->toContain($leak, "{$label} leaks [{$leak}]");
        }
    }

    // ...and all the same, so no failure differs from any other.
    expect(collect($shapes)->map(fn (array $shape): string => json_encode($shape))->unique()->values()->all())->toHaveCount(1);
}

test('hub verification GETs answer every unresolvable tenant/source with one identical, model-free 404', function (string $channel, LeadSourceType $type) {
    ['tenant' => $tenant, 'suspended' => $suspended, 'other' => $other] = oracleTenants();
    oracleSource($suspended, $type);            // source exists, but its tenant is suspended
    oracleSource($other, $type, active: false); // tenant active, source inactive
    $query = '?hub_mode=subscribe&hub_verify_token=secret-token&hub_challenge=42';

    $shapes = [
        'unknown tenant' => oracleShape($this->getJson(route("api.webhook.{$channel}.verify", 'TEN-999999').$query)),
        'existing tenant, no source' => oracleShape($this->getJson(route("api.webhook.{$channel}.verify", $tenant->tenant_code).$query)),
        'suspended tenant' => oracleShape($this->getJson(route("api.webhook.{$channel}.verify", $suspended->tenant_code).$query)),
        'inactive source' => oracleShape($this->getJson(route("api.webhook.{$channel}.verify", $other->tenant_code).$query)),
    ];

    expectOneUniformNotFound($shapes);
})->with([
    'facebook' => ['facebook', LeadSourceType::FacebookMessenger],
    'instagram' => ['instagram', LeadSourceType::Instagram],
    'whatsapp' => ['whatsapp', LeadSourceType::Whatsapp],
]);

test('the legacy website-form POST answers every unresolvable tenant/source with the same uniform 404', function () {
    ['tenant' => $tenant, 'suspended' => $suspended, 'other' => $other] = oracleTenants();
    oracleSource($suspended, LeadSourceType::WebsiteForm);
    oracleSource($other, LeadSourceType::WebsiteForm, active: false);
    oracleSource($tenant, LeadSourceType::Whatsapp); // a source of ANOTHER type must not count

    $post = fn (string $code): TestResponse => $this->postJson(route('api.webhook.website-form', $code), ['name' => 'Jane Doe'], ['X-Api-Key' => 'test-key-123']);

    expectOneUniformNotFound([
        'unknown tenant' => oracleShape($post('TEN-999999')),
        'existing tenant, no website-form source' => oracleShape($post($tenant->tenant_code)),
        'suspended tenant' => oracleShape($post($suspended->tenant_code)),
        'inactive source' => oracleShape($post($other->tenant_code)),
    ]);

    expect(Lead::count())->toBe(0);
});

test('the form-builder POST answers every unresolvable tenant/form/token combination with the same uniform 404', function () {
    ['tenant' => $tenant, 'suspended' => $suspended, 'other' => $other] = oracleTenants();
    ['tenant' => $third] = tenantWithLeadManagement();
    oracleForm($tenant, 'good-token');
    oracleForm($suspended, 'suspended-token');
    oracleForm($other, 'inactive-token', active: false);
    oracleForm($third, 'third-token');

    $post = fn (string $code, string $token): TestResponse => $this->postJson(route('api.webhook.website-form.submit', [$code, $token]), ['name' => 'Jane Doe']);

    expectOneUniformNotFound([
        'unknown tenant, unknown token' => oracleShape($post('TEN-999999', 'nope')),
        'unknown tenant, real token' => oracleShape($post('TEN-999999', 'good-token')),
        'existing tenant, unknown token' => oracleShape($post($tenant->tenant_code, 'nope')),
        'existing tenant, another tenant\'s token' => oracleShape($post($tenant->tenant_code, 'third-token')),
        'suspended tenant, its own token' => oracleShape($post($suspended->tenant_code, 'suspended-token')),
        'inactive form' => oracleShape($post($other->tenant_code, 'inactive-token')),
    ]);

    expect(Lead::count())->toBe(0);
});

test('every unresolvable-webhook 404 across all route families is the same response', function () {
    ['tenant' => $tenant] = oracleTenants();
    $query = '?hub_mode=subscribe&hub_verify_token=secret-token&hub_challenge=42';

    expectOneUniformNotFound([
        'facebook verify' => oracleShape($this->getJson(route('api.webhook.facebook.verify', 'TEN-999999').$query)),
        'instagram verify' => oracleShape($this->getJson(route('api.webhook.instagram.verify', $tenant->tenant_code).$query)),
        'whatsapp verify' => oracleShape($this->getJson(route('api.webhook.whatsapp.verify', 'TEN-999999').$query)),
        'legacy website form' => oracleShape($this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [], ['X-Api-Key' => 'k'])),
        'form builder' => oracleShape($this->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, 'nope']), [])),
    ]);
});

test('the chat POST webhooks still answer an unknown tenant and an existing tenant without a source identically (200)', function (string $channel) {
    ['tenant' => $tenant] = oracleTenants();

    $unknown = oracleShape($this->postJson(route("api.webhook.{$channel}", 'TEN-999999'), ['object' => 'page', 'entry' => []]));
    $existing = oracleShape($this->postJson(route("api.webhook.{$channel}", $tenant->tenant_code), ['object' => 'page', 'entry' => []]));

    expect($unknown)->toBe($existing);
    expect($unknown[0])->toBe(200);
})->with(['facebook', 'instagram', 'whatsapp']);

test('valid webhook requests and credential checks behave exactly as before', function () {
    ['tenant' => $tenant] = oracleTenants();
    $query = fn (string $token): string => "?hub_mode=subscribe&hub_verify_token={$token}&hub_challenge=42";

    foreach ([['facebook', LeadSourceType::FacebookMessenger], ['instagram', LeadSourceType::Instagram], ['whatsapp', LeadSourceType::Whatsapp]] as [$channel, $type]) {
        ['tenant' => $t] = tenantWithLeadManagement();
        oracleSource($t, $type);

        // valid handshake: 200 with the challenge; wrong verify token: the credentials' own 403 (unchanged)
        $this->get(route("api.webhook.{$channel}.verify", $t->tenant_code).$query('secret-token'))->assertOk()->assertSee('42');
        $this->get(route("api.webhook.{$channel}.verify", $t->tenant_code).$query('wrong'))->assertForbidden();
    }

    // legacy website form: right key creates the lead, wrong key is the API key's own 401
    oracleSource($tenant, LeadSourceType::WebsiteForm);
    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), ['name' => 'Jane Doe', 'email' => 'jane@example.com'], ['X-Api-Key' => 'test-key-123'])->assertOk()->assertJson(['status' => 'ok']);
    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), ['name' => 'Jane Doe'], ['X-Api-Key' => 'wrong'])->assertUnauthorized();
    expect(Lead::where('tenant_id', $tenant->id)->where('email', 'jane@example.com')->count())->toBe(1);

    // form builder: a valid token creates the lead
    ['tenant' => $formTenant] = tenantWithLeadManagement();
    oracleForm($formTenant, 'valid-token');
    $this->postJson(route('api.webhook.website-form.submit', [$formTenant->tenant_code, 'valid-token']), ['name' => 'Form Person'])->assertOk()->assertJson(['status' => 'ok']);
    expect(Lead::where('tenant_id', $formTenant->id)->where('name', 'Form Person')->count())->toBe(1);
});
