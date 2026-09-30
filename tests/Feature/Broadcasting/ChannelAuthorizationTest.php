<?php

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\TenantUser;

beforeEach(function () {
    // The "null" broadcaster used in tests skips channel authorization
    // entirely, so switch to the Pusher-protocol driver (Reverb) which
    // actually evaluates the callbacks in routes/channels.php. The channel
    // callbacks were registered against the "null" driver at boot time, so
    // re-require the routes file to register them on the "reverb" driver too.
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
});

test('a tenant user can authenticate on their own tenant channel', function () {
    $tenant = Tenant::factory()->create();
    $user = TenantUser::factory()->create(['tenant_id' => $tenant->id]);

    $response = $this->actingAs($user)->post('/broadcasting/auth', [
        'channel_name' => 'private-tenant.'.$tenant->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertOk();
});

test('a tenant user cannot authenticate on another tenant\'s channel', function () {
    $tenant = Tenant::factory()->create();
    $user = TenantUser::factory()->create(['tenant_id' => $tenant->id]);

    $otherTenant = Tenant::factory()->create();

    $response = $this->actingAs($user)->post('/broadcasting/auth', [
        'channel_name' => 'private-tenant.'.$otherTenant->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertForbidden();
});

test('a client admin can authenticate on any lead in their tenant', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $response = $this->actingAs($clientAdmin)->post('/broadcasting/auth', [
        'channel_name' => 'private-lead.'.$lead->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertOk();
});

test('a client user assigned to the lead can authenticate on its channel', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    $response = $this->actingAs($rep)->post('/broadcasting/auth', [
        'channel_name' => 'private-lead.'.$lead->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertOk();
});

test('a client user not assigned to the lead cannot authenticate on its channel', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $response = $this->actingAs($rep)->post('/broadcasting/auth', [
        'channel_name' => 'private-lead.'.$lead->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertForbidden();
});

test('a tenant user cannot authenticate on another tenant\'s lead channel', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id]);

    $response = $this->actingAs($clientAdminA)->post('/broadcasting/auth', [
        'channel_name' => 'private-lead.'.$leadB->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertForbidden();
});

test('a tenant user can authenticate on their own notification channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $response = $this->actingAs($clientAdmin)->post('/broadcasting/auth', [
        'channel_name' => 'private-tenant-user.'.$clientAdmin->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertOk();
});

test('a tenant user cannot authenticate on another tenant user\'s notification channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);

    $response = $this->actingAs($clientAdmin)->post('/broadcasting/auth', [
        'channel_name' => 'private-tenant-user.'.$rep->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertForbidden();
});

test('a client admin can authenticate on their own tenant\'s lead-import channel', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::CsvImport]);
    $import = LeadImport::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    $response = $this->actingAs($clientAdmin)->post('/broadcasting/auth', [
        'channel_name' => 'private-lead-import.'.$import->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertOk();
});

test('a tenant user cannot authenticate on another tenant\'s lead-import channel', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $clientAdminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $sourceB = LeadSource::factory()->create(['tenant_id' => $tenantB->id, 'type' => LeadSourceType::CsvImport]);
    $importB = LeadImport::factory()->create(['tenant_id' => $tenantB->id, 'source_id' => $sourceB->id]);

    $response = $this->actingAs($clientAdminA)->post('/broadcasting/auth', [
        'channel_name' => 'private-lead-import.'.$importB->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertForbidden();
});

// ── WhatsApp Call Backup / Overflow Receiver, Phase 2 — softphone presence ──

test('a tenant user can join their own tenant\'s softphone presence channel', function () {
    $tenant = Tenant::factory()->create();
    $user = TenantUser::factory()->create(['tenant_id' => $tenant->id]);

    $response = $this->actingAs($user)->post('/broadcasting/auth', [
        'channel_name' => 'presence-tenant-softphone.'.$tenant->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertOk();
});

test('a tenant user cannot join another tenant\'s softphone presence channel', function () {
    $tenant = Tenant::factory()->create();
    $user = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $otherTenant = Tenant::factory()->create();

    $response = $this->actingAs($user)->post('/broadcasting/auth', [
        'channel_name' => 'presence-tenant-softphone.'.$otherTenant->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertForbidden();
});

test('the softphone presence channel exposes only the member id, never a name, role, permission or phone number', function () {
    $tenant = Tenant::factory()->create();
    $user = TenantUser::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sentinel Presence Name']);

    $response = $this->actingAs($user)->post('/broadcasting/auth', [
        'channel_name' => 'presence-tenant-softphone.'.$tenant->id,
        'socket_id' => '1234.1234',
    ]);

    $response->assertOk();
    $channelData = json_decode($response->json('channel_data'), true);

    expect($channelData['user_id'])->toBe($user->id);
    expect($channelData['user_info'])->toBe(['id' => $user->id]);
    expect(json_encode($channelData))->not->toContain('Sentinel Presence Name');
});
