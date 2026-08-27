<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;

test('a client admin cannot view another tenant\'s users', function () {
    $tenantA = Tenant::factory()->create();
    $roleA = Role::factory()->forTenant($tenantA)->ownerRole()->create();
    $adminA = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenantA->id, 'role_id' => $roleA->id]);

    $tenantB = Tenant::factory()->create();
    $roleB = Role::factory()->forTenant($tenantB)->ownerRole()->create();
    $userB = TenantUser::factory()->create(['tenant_id' => $tenantB->id, 'role_id' => $roleB->id]);

    expect($adminA->can('view', $userB))->toBeFalse();
});

test('a client admin cannot update or delete another tenant\'s role', function () {
    $tenantA = Tenant::factory()->create();
    $roleA = Role::factory()->forTenant($tenantA)->ownerRole()->create();
    $adminA = TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenantA->id, 'role_id' => $roleA->id]);

    $tenantB = Tenant::factory()->create();
    $roleB = Role::factory()->forTenant($tenantB)->create();

    expect($adminA->can('update', $roleB))->toBeFalse();
    expect($adminA->can('delete', $roleB))->toBeFalse();
});
