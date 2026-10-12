<?php

use App\Livewire\Platform\Clients\Show;
use App\Models\PlatformUser;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
 * A tenant's Client Admin account is provisioned by Platform Admin (see
 * App\Actions\Platform\CreateClient) — only Platform Admin can deactivate
 * or reactivate it (TenantUserPolicy's own self-action guard blocks the
 * Client Admin from doing this to themselves, see SelfActionRestrictionTest).
 * Deactivating cascades to every other active tenant user alongside the
 * Client Admin; reactivating restores exactly the users this suspended.
 */

test('a platform admin deactivates the client admin and every other active tenant user', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->call('deactivateClientAdmin');

    expect($clientAdmin->refresh()->is_active)->toBeFalse();
    expect($clientAdmin->deactivated_by_platform_at)->not->toBeNull();
    expect($rep->refresh()->is_active)->toBeFalse();
    expect($rep->deactivated_by_platform_at)->not->toBeNull();
});

test('a user already inactive before the deactivation is left untouched', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $alreadyInactive = TenantUser::factory()->inactive()->create(['tenant_id' => $tenant->id]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->call('deactivateClientAdmin');

    expect($alreadyInactive->refresh()->is_active)->toBeFalse();
    expect($alreadyInactive->deactivated_by_platform_at)->toBeNull();
});

test('reactivating restores exactly the users the deactivation suspended, not an independently inactive one', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $alreadyInactive = TenantUser::factory()->inactive()->create(['tenant_id' => $tenant->id]);
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')->test(Show::class, ['tenant' => $tenant])->call('deactivateClientAdmin');

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->call('activateClientAdmin');

    expect($clientAdmin->refresh()->is_active)->toBeTrue();
    expect($clientAdmin->deactivated_by_platform_at)->toBeNull();
    expect($rep->refresh()->is_active)->toBeTrue();
    expect($rep->deactivated_by_platform_at)->toBeNull();
    expect($alreadyInactive->refresh()->is_active)->toBeFalse();
});

test('the deactivation confirm modal flow deactivates only after confirmation', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $component = Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->call('confirmDeactivateClientAdmin')
        ->assertSet('showDeactivateClientAdminModal', true);

    expect($clientAdmin->refresh()->is_active)->toBeTrue();

    $component->call('deactivateClientAdminConfirmed')->assertSet('showDeactivateClientAdminModal', false);

    expect($clientAdmin->refresh()->is_active)->toBeFalse();
});

test('a platform admin without client-management update permission cannot deactivate the client admin', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $restrictedAdmin = PlatformUser::factory()->create();

    // deactivateClientAdmin()'s own Gate::authorize('update', $this->tenant)
    // check is the same ability check mount() performs (mount() would
    // already block a restricted admin from reaching this action at all).
    // Tested directly at the Gate level, same established pattern as
    // PlatformLeadSourceDeletionTest's identical case, to avoid a Livewire-
    // testing quirk where a mount-time authorization failure leaves the
    // component's snapshot in a state a subsequent ->call() can't reuse.
    expect(Gate::forUser($restrictedAdmin)->denies('update', $tenant))->toBeTrue();

    expect($clientAdmin->refresh()->is_active)->toBeTrue();
});

test('a deactivated client admin cannot log in', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')->test(Show::class, ['tenant' => $tenant])->call('deactivateClientAdmin');

    // Not auth()->check(): Livewire::actingAs(..., 'platform') just called
    // auth()->shouldUse('platform'), so the default guard for the rest of
    // this test is 'platform', not 'web' — check the tenant guard
    // explicitly, or this would be asserting the still-logged-in mother
    // admin's own (unrelated) platform session instead.
    $this->post('/login', ['email' => $clientAdmin->email, 'password' => 'password'])->assertSessionHasErrors();
    expect(auth('web')->check())->toBeFalse();
});
