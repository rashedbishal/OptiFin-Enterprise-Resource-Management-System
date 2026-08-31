<?php

use App\Enums\LeadSourceType;
use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadSource;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('creating a linkedin source requires an organization urn and lead type', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->set('type', LeadSourceType::LinkedInLeadGen->value)
        ->set('name', 'LinkedIn Lead Gen')
        ->call('save')
        ->assertHasErrors(['config.organization_urn' => 'required', 'config.lead_type' => 'required']);

    expect(LeadSource::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('creating a linkedin source without a token succeeds, since the token only arrives via oauth', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->set('type', LeadSourceType::LinkedInLeadGen->value)
        ->set('name', 'LinkedIn Lead Gen')
        ->set('config.organization_urn', 'urn:li:organization:12345')
        ->set('config.lead_type', 'SPONSORED')
        ->call('save')
        ->assertHasNoErrors();

    $source = LeadSource::where('tenant_id', $tenant->id)->firstOrFail();
    expect($source->config)->toMatchArray([
        'organization_urn' => 'urn:li:organization:12345',
        'lead_type' => 'SPONSORED',
        'fetch_interval_minutes' => 15,
    ]);
    expect($source->config['access_token'] ?? null)->toBeNull();
});

test('the organization urn must look like a linkedin urn', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->set('type', LeadSourceType::LinkedInLeadGen->value)
        ->set('name', 'LinkedIn Lead Gen')
        ->set('config.organization_urn', 'not-a-urn')
        ->set('config.lead_type', 'SPONSORED')
        ->call('save')
        ->assertHasErrors(['config.organization_urn']);
});

test('editing a linkedin source without changing the masked access token preserves the real stored value', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::LinkedInLeadGen,
        'config' => [
            'organization_urn' => 'urn:li:organization:12345',
            'lead_type' => 'SPONSORED',
            'access_token' => 'real-linkedin-access-token',
            'refresh_token' => 'real-linkedin-refresh-token',
        ],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('edit', $source->id)
        ->assertSet('config.access_token', '••••••••')
        ->assertSet('config.refresh_token', '••••••••')
        ->call('save');

    $source->refresh();

    expect($source->config)->toMatchArray([
        'access_token' => 'real-linkedin-access-token',
        'refresh_token' => 'real-linkedin-refresh-token',
    ]);
});

test('testing a linkedin source with no organization urn reports failure without an http call', function () {
    Http::fake();

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::LinkedInLeadGen,
        'config' => ['lead_type' => 'SPONSORED'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger');

    Http::assertNothingSent();
});

test('testing a linkedin source that has not been connected via oauth yet reports a distinct failure without an http call', function () {
    Http::fake();

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::LinkedInLeadGen,
        'config' => ['organization_urn' => 'urn:li:organization:12345', 'lead_type' => 'SPONSORED'],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger'
            && str_contains($params['slots']['text'], 'Connect with LinkedIn'));

    Http::assertNothingSent();
});

test('testing a linkedin source without an api version configured reports failure without an http call', function () {
    config(['services.linkedin.api_version' => null]);
    Http::fake();

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::LinkedInLeadGen,
        'config' => [
            'organization_urn' => 'urn:li:organization:12345',
            'lead_type' => 'SPONSORED',
            'access_token' => 'a-token',
        ],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger');

    Http::assertNothingSent();
});

test('testing a linkedin source with a valid token reports success', function () {
    config(['services.linkedin.api_version' => '202608']);
    Http::fake(['api.linkedin.com/rest/leadForms*' => Http::response(['elements' => []])]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::LinkedInLeadGen,
        'config' => [
            'organization_urn' => 'urn:li:organization:12345',
            'lead_type' => 'SPONSORED',
            'access_token' => 'valid-token',
        ],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.linkedin.com/rest/leadForms?')
        && $request['q'] === 'owner'
        && $request['owner'] === '(organization:urn:li:organization:12345)'
        && (string) $request['count'] === '1'
        && $request->hasHeader('Authorization', 'Bearer valid-token')
        && $request->hasHeader('Linkedin-Version', '202608')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0'));
});

test('testing a linkedin source against a sponsored account urn queries the sponsoredAccount owner key', function () {
    config(['services.linkedin.api_version' => '202608']);
    Http::fake(['api.linkedin.com/rest/leadForms*' => Http::response(['elements' => []])]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::LinkedInLeadGen,
        'config' => [
            'organization_urn' => 'urn:li:sponsoredAccount:999',
            'lead_type' => 'SPONSORED',
            'access_token' => 'valid-token',
        ],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success');

    Http::assertSent(fn ($request) => $request['owner'] === '(sponsoredAccount:urn:li:sponsoredAccount:999)');
});

test('testing a linkedin source with an invalid token reports failure', function () {
    config(['services.linkedin.api_version' => '202608']);
    Http::fake(['api.linkedin.com/*' => Http::response(['message' => 'Invalid access token.'], 401)]);

    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::LinkedInLeadGen,
        'config' => [
            'organization_urn' => 'urn:li:organization:12345',
            'lead_type' => 'SPONSORED',
            'access_token' => 'bad-token',
        ],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('testConnection', $source->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger'
            && str_contains($params['slots']['text'], 'Invalid access token.'));
});

test('a linkedin source has no live webhook url yet and falls back to the polled-interval display', function () {
    $tenant = Tenant::factory()->create();
    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::LinkedInLeadGen,
        'config' => ['organization_urn' => 'urn:li:organization:12345', 'lead_type' => 'SPONSORED', 'fetch_interval_minutes' => 20],
    ]);

    expect($source->webhookUrl())->toBeNull();
});
