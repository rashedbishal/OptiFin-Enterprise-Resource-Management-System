<?php

use App\Actions\Platform\CreateClient;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\PlatformUser;
use App\Models\Tenant;

test('a lead created for a tenant gets a sequential {tenant prefix}-LEAD-{n} identifier', function () {
    $tenant = Tenant::factory()->create(['name' => 'Acme Corp', 'lead_id_prefix' => 'ACME-CORP']);

    $first = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $second = Lead::factory()->create(['tenant_id' => $tenant->id]);

    expect($first->identifier)->toBe('ACME-CORP-LEAD-1');
    expect($second->identifier)->toBe('ACME-CORP-LEAD-2');
});

test('deleting a lead never reclaims its number for a later lead', function () {
    $tenant = Tenant::factory()->create(['lead_id_prefix' => 'GAPTEST']);

    $first = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $first->delete();

    $second = Lead::factory()->create(['tenant_id' => $tenant->id]);

    expect($second->identifier)->toBe('GAPTEST-LEAD-2');
});

test('the identifier column cannot be set through mass assignment', function () {
    $tenant = Tenant::factory()->create(['lead_id_prefix' => 'GUARDED']);
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id]);

    $lead = Lead::create([
        'tenant_id' => $tenant->id,
        'source_id' => $source->id,
        'name' => 'Someone',
        'status_id' => $tenant->initialLeadStatus()?->id,
        'identifier' => 'SOMETHING-ELSE',
    ]);

    expect($lead->identifier)->toBe('GUARDED-LEAD-1');
});

test('two tenants whose name starts with the same first word get unique lead id prefixes, so their lead identifiers never collide', function () {
    $platformAdmin = PlatformUser::factory()->create();

    $tenantA = app(CreateClient::class)->handle(
        tenantData: ['name' => 'Duplicate Name Ltd'],
        adminData: ['name' => 'Owner A', 'email' => 'owner-a@example.com', 'password' => 'password'],
        createdBy: $platformAdmin,
    );
    $tenantB = app(CreateClient::class)->handle(
        tenantData: ['name' => 'Duplicate Industries'],
        adminData: ['name' => 'Owner B', 'email' => 'owner-b@example.com', 'password' => 'password'],
        createdBy: $platformAdmin,
    );

    expect($tenantA->lead_id_prefix)->toBe('DUPLICATE');
    expect($tenantB->lead_id_prefix)->toBe('DUPLICATE-2');

    $status = LeadStatus::factory()->create();
    $leadA = Lead::factory()->create(['tenant_id' => $tenantA->id, 'status_id' => $status->id]);
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id, 'status_id' => $status->id]);

    expect($leadA->identifier)->toBe('DUPLICATE-LEAD-1');
    expect($leadB->identifier)->toBe('DUPLICATE-2-LEAD-1');
});

test('the lead id prefix uses only the first word of a multi-word tenant name, and the whole name when it is a single word', function () {
    expect(Tenant::generateLeadIdPrefix('Opticore Solutions Ltd'))->toBe('OPTICORE');
    expect(Tenant::generateLeadIdPrefix('Acme'))->toBe('ACME');
});
