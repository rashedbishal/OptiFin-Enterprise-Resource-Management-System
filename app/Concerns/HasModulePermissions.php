<?php

namespace App\Concerns;

trait HasModulePermissions
{
    /**
     * Whether this user implicitly bypasses all module/permission checks
     * (the Mother Admin). Overridden by models that can hold that status.
     */
    public function bypassesAuthorization(): bool
    {
        return false;
    }

    public function hasModulePermission(string $moduleSlug, string $permissionSlug): bool
    {
        if ($this->bypassesAuthorization()) {
            return true;
        }

        $role = $this->role;

        if ($role === null) {
            return false;
        }

        return $role->hasModule($moduleSlug) && $role->hasPermission($permissionSlug);
    }
}
