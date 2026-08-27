<?php

namespace App\Policies;

use App\Models\Module;
use App\Models\PlatformUser;

class ModulePolicy
{
    public function viewAny(PlatformUser $actor): bool
    {
        return $actor->hasModulePermission('module-management', 'view');
    }

    public function view(PlatformUser $actor, Module $module): bool
    {
        return $actor->hasModulePermission('module-management', 'view');
    }

    public function create(PlatformUser $actor): bool
    {
        return $actor->hasModulePermission('module-management', 'create');
    }

    public function update(PlatformUser $actor, Module $module): bool
    {
        return $actor->hasModulePermission('module-management', 'update');
    }

    public function delete(PlatformUser $actor, Module $module): bool
    {
        return ! $module->is_system && $actor->hasModulePermission('module-management', 'delete');
    }
}
