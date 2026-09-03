<?php

use App\Livewire\Tenant\Roles\Index as RolesIndex;
use App\Livewire\Tenant\Users\Index as UsersIndex;
use App\Models\Role;
use App\Models\TenantUser;
use Livewire\Livewire;

test('a client admin deleting a tenant role goes through the confirm modal', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $role = Role::factory()->forTenant($tenant)->create();

    Livewire::actingAs($clientAdmin)
        ->test(RolesIndex::class)
        ->call('confirmDelete', $role->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('confirmingDeleteId', $role->id)
        ->call('deleteConfirmed')
        ->assertSet('showDeleteModal', false);

    expect(Role::find($role->id))->toBeNull();
});

test('a client admin deleting a tenant user goes through the confirm modal', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $target = TenantUser::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(UsersIndex::class)
        ->call('confirmDelete', $target->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('confirmingDeleteId', $target->id)
        ->call('deleteConfirmed')
        ->assertSet('showDeleteModal', false);

    expect(TenantUser::find($target->id))->toBeNull();
});
