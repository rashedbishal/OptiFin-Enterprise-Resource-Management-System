<?php

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Support\Facades\Http;

test('fetching a lead ad by id prints the graph api response and does not create a lead', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/v20.0/leadgen-1*' => Http::response([
        'field_data' => [['name' => 'email', 'values' => ['a@example.com']]],
    ])]);

    $this->artisan('leads:fetch-test', ['leadgen_id' => 'leadgen-1', '--source' => $source->id])
        ->assertExitCode(0);

    expect(Lead::count())->toBe(0);
});

test('a permission error exits non-zero and hints at the leads_retrieval cause', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $source = LeadSource::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => LeadSourceType::FacebookLeadAds,
        'config' => ['page_id' => '1087258341146975', 'page_access_token' => 'page-token', 'verify_token' => 'secret'],
    ]);

    Http::fake(['graph.facebook.com/v20.0/leadgen-2*' => Http::response([
        'error' => ['message' => 'permission denied', 'code' => 100, 'error_subcode' => 33],
    ], 400)]);

    $this->artisan('leads:fetch-test', ['leadgen_id' => 'leadgen-2', '--source' => $source->id])
        ->expectsOutputToContain('leads_retrieval')
        ->assertExitCode(1);
});

test('no matching source fails cleanly', function () {
    $this->artisan('leads:fetch-test', ['leadgen_id' => 'leadgen-3', '--source' => 'does-not-exist'])
        ->assertExitCode(1);
});
