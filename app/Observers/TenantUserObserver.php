<?php

namespace App\Observers;

use App\Models\TenantUser;

class TenantUserObserver
{
    /**
     * A tenant can never lose its last active owner (Client Admin).
     */
    public function deleting(TenantUser $tenantUser): bool
    {
        return ! $this->isLastActiveOwner($tenantUser);
    }

    public function updating(TenantUser $tenantUser): bool
    {
        if (! $this->isLastActiveOwner($tenantUser)) {
            return true;
        }

        if ($tenantUser->isDirty('is_active') && ! $tenantUser->is_active) {
            return false;
        }

        if ($tenantUser->isDirty('role_id')) {
            // Role is changing; only block if the new role is not itself
            // the tenant's owner role (i.e. the owner would lose the role).
            $newRoleIsOwnerRole = $tenantUser->role()->getRelated()
                ->newQuery()
                ->whereKey($tenantUser->role_id)
                ->where('is_owner_role', true)
                ->exists();

            if (! $newRoleIsOwnerRole) {
                return false;
            }
        }

        return true;
    }

    private function isLastActiveOwner(TenantUser $tenantUser): bool
    {
        $originalRoleId = $tenantUser->getOriginal('role_id');

        $role = $tenantUser->role()->getRelated()->find($originalRoleId);

        if (! $role || ! $role->is_owner_role) {
            return false;
        }

        $otherActiveOwners = TenantUser::query()
            ->where('tenant_id', $tenantUser->tenant_id)
            ->where('role_id', $originalRoleId)
            ->where('is_active', true)
            ->when($tenantUser->exists, fn ($query) => $query->whereKeyNot($tenantUser->id))
            ->count();

        return $otherActiveOwners === 0;
    }
}
