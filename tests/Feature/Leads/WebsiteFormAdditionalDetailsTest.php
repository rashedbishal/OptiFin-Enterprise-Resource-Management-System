<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadSource;
use Livewire\Livewire;

test('a website form lead\'s extra fields render as an Additional Details list, under title-cased labels', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'source_data' => [
            'company_name' => 'Acme Corp',
            'preferred-contact-time' => 'Afternoons',
            'budget' => 5000,
        ],
    ]);

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    expect($html)->toContain('Additional Details');
    expect($html)->toContain('Company Name');
    expect($html)->toContain('Acme Corp');
    expect($html)->toContain('Preferred Contact Time');
    expect($html)->toContain('Afternoons');
    expect($html)->toContain('Budget');
    expect($html)->toContain('5000');
});

test('a multi-value website form field renders as a comma-joined list', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'source_data' => ['interests' => ['Web Design', 'SEO', 'Hosting']],
    ]);

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    expect($html)->toContain('Web Design, SEO, Hosting');
});

test('a website form lead with no extra fields shows no Additional Details section', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::WebsiteForm]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id, 'source_data' => []]);

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    expect($html)->not->toContain('Additional Details');
});

test('a non-website-form lead never shows an Additional Details section, even with source_data present', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Email]);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'source_data' => ['random_key' => 'random_value'],
    ]);

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    expect($html)->not->toContain('Additional Details');
});

test('a website form submission with arbitrary extra fields is captured and shown end to end', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::WebsiteForm,
        'config' => ['api_key' => 'test-key-123', 'allowed_domains' => []],
    ]);

    $this->postJson(route('api.webhook.website-form', $tenant->tenant_code), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'company' => 'Acme Corp',
        'project_type' => 'Website Redesign',
        'estimated_pages' => '12',
        'referral_source' => 'Google Search',
    ], ['X-Api-Key' => 'test-key-123'])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->where('email', 'jane@example.com')->firstOrFail();

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    expect($html)->toContain('Additional Details');
    expect($html)->toContain('Company');
    expect($html)->toContain('Acme Corp');
    expect($html)->toContain('Project Type');
    expect($html)->toContain('Website Redesign');
    expect($html)->toContain('Estimated Pages');
    expect($html)->toContain('12');
    expect($html)->toContain('Referral Source');
    expect($html)->toContain('Google Search');
});
