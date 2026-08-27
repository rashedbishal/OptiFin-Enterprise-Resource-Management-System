<?php

namespace App\Observers;

use App\Models\Role;

class RoleObserver
{
    /**
     * System roles (e.g. a tenant's owner role) can never be deleted.
     */
    public function deleting(Role $role): bool
    {
        return ! $role->is_system;
    }
}
