<?php

use App\Actions\Platform\AssignModuleToTenant;
use App\Actions\Platform\RemoveModuleFromTenant;
use App\Enums\ModuleScope;
use App\Models\Module;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\RoleService;
use Illuminate\Validation\ValidationException;

test('a role cannot reference a module the tenant has not been assigned', function () {
    $tenant = Tenant::factory()->create();
    $role = Role::factory()->forTenant($tenant)->create();
    $module = Module::factory()->create(['scope' => ModuleScope::Tenant]);

    expect(fn () => app(RoleService::class)->syncModules($role, [$module->id]))
        ->toThrow(ValidationException::class);
});

test('a role can reference a module once the tenant is assigned it', function () {
    $tenant = Tenant::factory()->create();
    $role = Role::factory()->forTenant($tenant)->create();
    $module = Module::factory()->create(['scope' => ModuleScope::Tenant]);
    $platformAdmin = PlatformUser::factory()->create();

    app(AssignModuleToTenant::class)->handle($tenant, $module, $platformAdmin);

    app(RoleService::class)->syncModules($role, [$module->id]);

    expect($role->fresh()->hasModule($module->slug))->toBeTrue();
});

test('assigning a module to a tenant also grants it to the tenant owner role', function () {
    $tenant = Tenant::factory()->create();
    $ownerRole = Role::factory()->forTenant($tenant)->ownerRole()->create();
    $module = Module::factory()->create(['scope' => ModuleScope::Tenant]);
    $platformAdmin = PlatformUser::factory()->create();

    app(AssignModuleToTenant::class)->handle($tenant, $module, $platformAdmin);

    expect($ownerRole->fresh()->hasModule($module->slug))->toBeTrue();
});

test('removing a module from a tenant strips it from every role in that tenant', function () {
    $tenant = Tenant::factory()->create();
    $ownerRole = Role::factory()->forTenant($tenant)->ownerRole()->create();
    $module = Module::factory()->create(['scope' => ModuleScope::Tenant]);
    $platformAdmin = PlatformUser::factory()->create();

    app(AssignModuleToTenant::class)->handle($tenant, $module, $platformAdmin);
    app(RemoveModuleFromTenant::class)->handle($tenant, $module);

    expect($tenant->fresh()->hasModule($module->slug))->toBeFalse();
    expect($ownerRole->fresh()->hasModule($module->slug))->toBeFalse();
});

test('a platform module cannot be attached to a client role', function () {
    $tenant = Tenant::factory()->create();
    $role = Role::factory()->forTenant($tenant)->create();
    $platformModule = Module::factory()->platformScope()->create();

    expect(fn () => app(RoleService::class)->syncModules($role, [$platformModule->id]))
        ->toThrow(ValidationException::class);
});
