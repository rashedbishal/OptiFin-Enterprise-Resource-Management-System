<?php

namespace App\Policies;

use App\Models\PlatformUser;

class PlatformUserPolicy
{
    public function viewAny(PlatformUser $actor): bool
    {
        return $actor->hasModulePermission('admin-management', 'view');
    }

    public function view(PlatformUser $actor, PlatformUser $target): bool
    {
        return $actor->hasModulePermission('admin-management', 'view');
    }

    public function create(PlatformUser $actor): bool
    {
        return $actor->hasModulePermission('admin-management', 'create');
    }

    public function update(PlatformUser $actor, PlatformUser $target): bool
    {
        return ! $target->isMotherAdmin() && $actor->hasModulePermission('admin-management', 'update');
    }

    public function delete(PlatformUser $actor, PlatformUser $target): bool
    {
        return ! $target->isMotherAdmin() && $actor->hasModulePermission('admin-management', 'delete');
    }
}
