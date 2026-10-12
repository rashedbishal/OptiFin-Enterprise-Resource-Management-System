<?php

use App\Livewire\Tenant\Users\Index;
use App\Models\Role;
use App\Models\TenantUser;
use Livewire\Livewire;

/*
 * A Client Admin's own account is provisioned by Platform Admin (see
 * App\Actions\Platform\CreateClient) — see TenantUserPolicy::update()/
 * delete()'s own self-action guard. This covers every action that shares
 * those two gates (edit/save/toggleActive via update(), delete via
 * delete()) plus the Blade view's button visibility.
 */

test('a client admin does not see edit/deactivate/delete buttons on their own row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $other = TenantUser::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertDontSeeHtml("wire:click=\"edit('{$clientAdmin->id}')\"")
        ->assertDontSeeHtml("wire:click=\"toggleActive('{$clientAdmin->id}')\"")
        ->assertDontSeeHtml("wire:click=\"confirmDelete('{$clientAdmin->id}')\"")
        ->assertSeeHtml("wire:click=\"edit('{$other->id}')\"")
        ->assertSeeHtml("wire:click=\"toggleActive('{$other->id}')\"")
        ->assertSeeHtml("wire:click=\"confirmDelete('{$other->id}')\"");
});

test('a client admin cannot open the edit modal for their own account', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)->test(Index::class)->call('edit', $clientAdmin->id)->assertForbidden();
});

test('a client admin cannot save changes to their own name/email/role via the users page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $otherRole = Role::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->set('editingId', $clientAdmin->id)
        ->set('name', 'Hacked Name')
        ->set('email', 'hacked@example.com')
        ->set('role_id', $otherRole->id)
        ->call('save')
        ->assertForbidden();

    $clientAdmin->refresh();
    expect($clientAdmin->name)->not->toBe('Hacked Name');
    expect($clientAdmin->role_id)->not->toBe($otherRole->id);
});

test('a client admin cannot deactivate their own account via the users page', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)->test(Index::class)->call('toggleActive', $clientAdmin->id)->assertForbidden();

    expect($clientAdmin->refresh()->is_active)->toBeTrue();
});

test('a client admin cannot delete their own account, even when another client admin exists in the tenant', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    TenantUser::factory()->clientAdmin()->create(['tenant_id' => $tenant->id, 'role_id' => $clientAdmin->role_id]);

    Livewire::actingAs($clientAdmin)->test(Index::class)->call('delete', $clientAdmin->id)->assertForbidden();

    expect(TenantUser::find($clientAdmin->id))->not->toBeNull();
});

test('a client admin can still edit, deactivate, and delete another user as before', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $other = TenantUser::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleActive', $other->id);

    expect($other->refresh()->is_active)->toBeFalse();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('delete', $other->id);

    expect(TenantUser::find($other->id))->toBeNull();
});
