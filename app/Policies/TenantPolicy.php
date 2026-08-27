<?php

namespace App\Policies;

use App\Models\PlatformUser;
use App\Models\Tenant;

class TenantPolicy
{
    public function viewAny(PlatformUser $actor): bool
    {
        return $actor->hasModulePermission('client-management', 'view');
    }

    public function view(PlatformUser $actor, Tenant $tenant): bool
    {
        return $actor->hasModulePermission('client-management', 'view');
    }

    public function create(PlatformUser $actor): bool
    {
        return $actor->hasModulePermission('client-management', 'create');
    }

    public function update(PlatformUser $actor, Tenant $tenant): bool
    {
        return $actor->hasModulePermission('client-management', 'update');
    }

    public function delete(PlatformUser $actor, Tenant $tenant): bool
    {
        return $actor->hasModulePermission('client-management', 'delete');
    }
}
