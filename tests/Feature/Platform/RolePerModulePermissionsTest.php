<?php

use App\Actions\Platform\AssignModuleToTenant;
use App\Actions\Platform\CreatePlatformRole;
use App\Enums\ModuleScope;
use App\Livewire\Platform\Roles\Index as PlatformRolesIndex;
use App\Livewire\Tenant\Roles\Index as TenantRolesIndex;
use App\Models\Module;
use App\Models\Permission;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Services\RoleService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('creating a platform role can grant different permissions per module', function () {
    (new PermissionSeeder)->run();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $moduleA = Module::factory()->platformScope()->create(['name' => 'Module A', 'slug' => 'module-a']);
    $moduleB = Module::factory()->platformScope()->create(['name' => 'Module B', 'slug' => 'module-b']);

    $view = Permission::where('slug', 'view')->value('id');
    $create = Permission::where('slug', 'create')->value('id');
    $delete = Permission::where('slug', 'delete')->value('id');

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(PlatformRolesIndex::class)
        ->set('name', 'Module Manager')
        ->set('moduleIds', [$moduleA->id, $moduleB->id])
        ->set('modulePermissions', [
            $moduleA->id => [$view, $create],
            $moduleB->id => [$view, $delete],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::whereNull('tenant_id')->where('name', 'Module Manager')->firstOrFail();

    expect($role->hasPermissionForModule('module-a', 'view'))->toBeTrue();
    expect($role->hasPermissionForModule('module-a', 'create'))->toBeTrue();
    expect($role->hasPermissionForModule('module-a', 'delete'))->toBeFalse();

    expect($role->hasPermissionForModule('module-b', 'view'))->toBeTrue();
    expect($role->hasPermissionForModule('module-b', 'delete'))->toBeTrue();
    expect($role->hasPermissionForModule('module-b', 'create'))->toBeFalse();
});

test('unchecking a module on edit drops only that module\'s permission grants', function () {
    (new PermissionSeeder)->run();
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $moduleA = Module::factory()->platformScope()->create(['name' => 'Module A', 'slug' => 'module-a']);
    $moduleB = Module::factory()->platformScope()->create(['name' => 'Module B', 'slug' => 'module-b']);
    $view = Permission::where('slug', 'view')->value('id');

    $role = app(CreatePlatformRole::class)->handle('Module Manager', [$moduleA->id, $moduleB->id], [
        $moduleA->id => [$view],
        $moduleB->id => [$view],
    ]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(PlatformRolesIndex::class)
        ->call('edit', $role->id)
        ->set('moduleIds', [$moduleA->id])
        ->call('save')
        ->assertHasNoErrors();

    $role->refresh();

    expect($role->modules->pluck('id')->all())->toBe([$moduleA->id]);
    expect($role->hasPermissionForModule('module-a', 'view'))->toBeTrue();
    expect($role->modulePermissions()->where('module_id', $moduleB->id)->exists())->toBeFalse();
});

test('a role cannot be granted a permission for a module it does not have', function () {
    (new PermissionSeeder)->run();
    $role = Role::factory()->create();
    $module = Module::factory()->create();
    $permission = Permission::first();

    expect(fn () => app(RoleService::class)->syncModulePermissions($role, [$module->id => [$permission->id]]))
        ->toThrow(ValidationException::class);
});

test('creating a tenant role can grant different permissions per module', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $secondModule = Module::factory()->create(['scope' => ModuleScope::Tenant, 'name' => 'Second Module', 'slug' => 'second-module']);
    app(AssignModuleToTenant::class)->handle($tenant, $secondModule, PlatformUser::factory()->motherAdmin()->create());

    $leadModule = Module::where('slug', 'lead-management')->firstOrFail();
    $view = Permission::where('slug', 'view')->value('id');
    $create = Permission::where('slug', 'create')->value('id');

    Livewire::actingAs($clientAdmin)
        ->test(TenantRolesIndex::class)
        ->set('name', 'Sales Manager')
        ->set('moduleIds', [$leadModule->id, $secondModule->id])
        ->set('modulePermissions', [
            $leadModule->id => [$view, $create],
            $secondModule->id => [$view],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::where('tenant_id', $tenant->id)->where('name', 'Sales Manager')->firstOrFail();

    expect($role->hasPermissionForModule('lead-management', 'view'))->toBeTrue();
    expect($role->hasPermissionForModule('lead-management', 'create'))->toBeTrue();
    expect($role->hasPermissionForModule('second-module', 'view'))->toBeTrue();
    expect($role->hasPermissionForModule('second-module', 'create'))->toBeFalse();
});
