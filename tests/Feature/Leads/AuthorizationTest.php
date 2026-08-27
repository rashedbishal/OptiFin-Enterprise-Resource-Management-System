<?php

use App\Models\Lead;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;

test('a tenant without the lead management module gets denied', function () {
    $tenant = Tenant::factory()->create();
    $role = Role::factory()->forTenant($tenant)->ownerRole()->create();
    $clientAdmin = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);

    $this->actingAs($clientAdmin)->get(route('tenant-leads.index'))->assertForbidden();
});

test('a client user without lead management permission cannot view leads', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();

    $role = Role::factory()->forTenant($tenant)->create();
    $userWithoutPermission = TenantUser::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);

    expect($userWithoutPermission->can('viewAny', Lead::class))->toBeFalse();
});

test('a client user cannot view another tenant\'s lead', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    $repA = clientUserWithLeadPermission($tenantA);

    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $leadB = Lead::factory()->create(['tenant_id' => $tenantB->id]);

    expect($repA->can('view', $leadB))->toBeFalse();
});

test('a client user can only view leads assigned to them, not every lead in the tenant', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);

    $ownLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    $otherLead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    expect($rep->can('view', $ownLead))->toBeTrue();
    expect($rep->can('view', $otherLead))->toBeFalse();
});
