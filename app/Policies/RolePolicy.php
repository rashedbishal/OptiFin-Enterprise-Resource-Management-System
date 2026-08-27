<?php

namespace App\Policies;

use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;

class RolePolicy
{
    /**
     * `create` is authorized against the *context* the role would be
     * created in, since a not-yet-created Role has no scope of its own:
     * pass a Tenant to authorize a client role, or nothing for a platform
     * role, e.g. `Gate::authorize('create', [Role::class, $tenant])`.
     */
    public function create(PlatformUser|TenantUser $actor, ?Tenant $tenant = null): bool
    {
        if ($tenant) {
            return $actor instanceof TenantUser
                && $actor->isClientAdmin()
                && $actor->tenant_id === $tenant->id;
        }

        return $actor instanceof PlatformUser && $actor->hasModulePermission('role-management', 'create');
    }

    public function view(PlatformUser|TenantUser $actor, Role $role): bool
    {
        return $this->authorizeForRole($actor, $role, 'view');
    }

    public function update(PlatformUser|TenantUser $actor, Role $role): bool
    {
        return $this->authorizeForRole($actor, $role, 'update');
    }

    public function delete(PlatformUser|TenantUser $actor, Role $role): bool
    {
        return ! $role->is_system && $this->authorizeForRole($actor, $role, 'delete');
    }

    private function authorizeForRole(PlatformUser|TenantUser $actor, Role $role, string $permission): bool
    {
        if ($role->isPlatformScoped()) {
            return $actor instanceof PlatformUser && $actor->hasModulePermission('role-management', $permission);
        }

        return $actor instanceof TenantUser
            && $actor->isClientAdmin()
            && $actor->tenant_id === $role->tenant_id;
    }
}
