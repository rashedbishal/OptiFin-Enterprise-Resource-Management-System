<?php

use App\Enums\LeadSourceType;
use App\Livewire\Tenant\LeadSources\Index;
use App\Models\LeadSource;
use App\Models\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/*
 * The one mutation on an otherwise fully read-only tenant Lead Sources page
 * (see LeadSourcePolicy::update()'s own docblock) — toggling which roles
 * receive unassigned leads from a given source (see
 * LeadSource::unassignedRoles()'s own docblock), Client-Admin-only.
 */

function tlsSource($tenant, LeadSourceType $type = LeadSourceType::Whatsapp): LeadSource
{
    return LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => $type]);
}

test('a Client Admin can grant a role access, and it persists', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = tlsSource($tenant);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    Livewire::actingAs($admin)->test(Index::class)
        ->call('completeInitialLoad')
        ->call('toggleUnassignedRole', $source->id, $rep->role_id);

    expect($source->unassignedRoles()->whereKey($rep->role_id)->exists())->toBeTrue();
});

test('calling it again revokes the grant', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = tlsSource($tenant);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source->unassignedRoles()->attach($rep->role_id);

    Livewire::actingAs($admin)->test(Index::class)
        ->call('completeInitialLoad')
        ->call('toggleUnassignedRole', $source->id, $rep->role_id);

    expect($source->unassignedRoles()->whereKey($rep->role_id)->exists())->toBeFalse();
});

test('a non-admin Client User cannot toggle the grant', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $nonAdmin = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $source = tlsSource($tenant);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    Livewire::actingAs($nonAdmin)->test(Index::class)
        ->call('completeInitialLoad')
        ->call('toggleUnassignedRole', $source->id, $rep->role_id)
        ->assertForbidden();

    expect($source->unassignedRoles()->whereKey($rep->role_id)->exists())->toBeFalse();
});

test('a lead source from another tenant is a 404, not a cross-tenant grant', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $foreignSource = tlsSource($otherTenant);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    expect(fn () => Livewire::actingAs($admin)->test(Index::class)
        ->call('completeInitialLoad')
        ->call('toggleUnassignedRole', $foreignSource->id, $rep->role_id))
        ->toThrow(ModelNotFoundException::class);
});

test('a role from another tenant is a 404, not a cross-tenant grant', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $source = tlsSource($tenant);
    $foreignRep = clientUserWithLeadPermission($otherTenant, ['view', 'update']);

    expect(fn () => Livewire::actingAs($admin)->test(Index::class)
        ->call('completeInitialLoad')
        ->call('toggleUnassignedRole', $source->id, $foreignRep->role_id))
        ->toThrow(ModelNotFoundException::class);
});

test('the role chips column only renders for a Client Admin', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $source = tlsSource($tenant);
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    Livewire::actingAs($admin)->test(Index::class)
        ->call('completeInitialLoad')
        ->assertSeeHtml('wire:click="toggleUnassignedRole(\''.$source->id.'\', \''.$rep->role_id.'\')"');

    Livewire::actingAs($rep)->test(Index::class)
        ->call('completeInitialLoad')
        ->assertDontSeeHtml('wire:click="toggleUnassignedRole(\''.$source->id.'\', \''.$rep->role_id.'\')"');
});

test('rolesEligibleForUnassigned only offers roles with Lead Management module access', function () {
    ['tenant' => $tenant, 'clientAdmin' => $admin] = tenantWithLeadManagement();
    $noAccessRole = Role::create(['tenant_id' => $tenant->id, 'name' => 'No Access Role', 'slug' => 'no-access-role']);

    $component = Livewire::actingAs($admin)->test(Index::class)->call('completeInitialLoad');
    $roleNames = $component->get('rolesEligibleForUnassigned')->pluck('name')->all();

    expect($roleNames)->not->toContain('No Access Role');
});
