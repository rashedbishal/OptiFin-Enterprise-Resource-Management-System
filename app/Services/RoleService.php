<?php

namespace App\Services;

use App\Enums\ModuleScope;
use App\Models\Module;
use App\Models\Role;
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
     * @param  array<int, string>  $permissionIds
     */
    public function syncPermissions(Role $role, array $permissionIds): void
    {
        $role->permissions()->sync($permissionIds);
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
