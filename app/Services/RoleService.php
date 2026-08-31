<?php

namespace App\Services;

use App\Enums\ModuleScope;
use App\Models\Module;
use App\Models\Role;
use App\Models\RoleModulePermission;
use Illuminate\Validation\ValidationException;

/**
 * Central place that enforces the Role -> Module -> Permission rules from
 * the platform's authorization architecture, so no controller/Livewire
 * component has to re-implement the scope checks itself.
 */
class RoleService
{
    /**
     * Attach the given modules to a role, after validating that every
     * module matches the role's scope (platform vs tenant) and, for a
     * tenant role, is currently assigned and active for that tenant.
     *
     * @param  array<int, string>  $moduleIds
     */
    public function syncModules(Role $role, array $moduleIds): void
    {
        $modules = Module::query()->whereKey($moduleIds)->get();

        foreach ($modules as $module) {
            $this->assertModuleMatchesRoleScope($role, $module);
        }

        $role->modules()->sync($modules->pluck('id'));
    }

    /**
     * Replace a role's per-module permission grants. Each key is a module
     * id the role currently has (via syncModules), and each value is the
     * list of permission ids granted for that module specifically — the
     * same permission can be granted for one module and withheld for
     * another.
     *
     * @param  array<string, array<int, string>>  $modulePermissions
     */
    public function syncModulePermissions(Role $role, array $modulePermissions): void
    {
        $roleModuleIds = $role->modules()->pluck('modules.id')->all();

        $grants = [];

        foreach ($modulePermissions as $moduleId => $permissionIds) {
            if (! in_array($moduleId, $roleModuleIds, true)) {
                throw ValidationException::withMessages([
                    'modulePermissions' => 'Permissions can only be granted for modules selected on this role.',
                ]);
            }

            foreach ($permissionIds as $permissionId) {
                $grants[] = [
                    'role_id' => $role->id,
                    'module_id' => $moduleId,
                    'permission_id' => $permissionId,
                ];
            }
        }

        $role->modulePermissions()->delete();

        if ($grants !== []) {
            RoleModulePermission::query()->insert($grants);
        }
    }

    public function assertModuleMatchesRoleScope(Role $role, Module $module): void
    {
        if ($role->isPlatformScoped()) {
            if ($module->scope !== ModuleScope::Platform) {
                throw ValidationException::withMessages([
                    'modules' => "\"{$module->name}\" is not a platform module and cannot be attached to a platform role.",
                ]);
            }

            return;
        }

        if ($module->scope !== ModuleScope::Tenant) {
            throw ValidationException::withMessages([
                'modules' => "\"{$module->name}\" is not a tenant module and cannot be attached to a client role.",
            ]);
        }

        if (! $role->tenant->hasModule($module->slug)) {
            throw ValidationException::withMessages([
                'modules' => "\"{$module->name}\" has not been assigned to this client and cannot be granted to a role.",
            ]);
        }
    }

    /**
     * Ensure a role cannot be deleted while users still hold it.
     */
    public function assertDeletable(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'This is a protected system role and cannot be deleted.',
            ]);
        }

        if ($role->platformUsers()->exists() || $role->tenantUsers()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'This role is still assigned to users and cannot be deleted.',
            ]);
        }
    }
}
