<?php

namespace App\Policies;

use App\Models\Currency;
use App\Models\PlatformUser;

/**
 * Gated by 'module-management', not 'lead-management' — same reasoning as
 * LeadStatusPolicy: this catalog lives on a Platform Admin master-data page
 * reached from /admin/modules, and 'lead-management' is a Tenant-scope
 * module RoleService never lets a platform role hold.
 */
class CurrencyPolicy
{
    public function viewAny(PlatformUser $actor): bool
    {
        return $actor->hasModulePermission('module-management', 'view');
    }

    public function view(PlatformUser $actor, Currency $currency): bool
    {
        return $actor->hasModulePermission('module-management', 'view');
    }

    public function create(PlatformUser $actor): bool
    {
        return $actor->hasModulePermission('module-management', 'create');
    }

    public function update(PlatformUser $actor, Currency $currency): bool
    {
        return $actor->hasModulePermission('module-management', 'update');
    }

    public function delete(PlatformUser $actor, Currency $currency): bool
    {
        return $actor->hasModulePermission('module-management', 'delete');
    }
}
