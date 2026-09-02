<?php

namespace App\Policies;

use App\Models\LeadStatus;
use App\Models\PlatformUser;

/**
 * Gated by 'module-management', not 'lead-management': this catalog is a
 * Platform Admin master-data page reached from /admin/modules, and
 * 'lead-management' is a Tenant-scope module — RoleService never lets a
 * platform role hold a tenant-scope module, so gating on it here would make
 * this page permanently inaccessible to every non-mother-admin Platform
 * Admin.
 */
class LeadStatusPolicy
{
    public function viewAny(PlatformUser $actor): bool
    {
        return $actor->hasModulePermission('module-management', 'view');
    }

    public function view(PlatformUser $actor, LeadStatus $status): bool
    {
        return $actor->hasModulePermission('module-management', 'view');
    }

    public function create(PlatformUser $actor): bool
    {
        return $actor->hasModulePermission('module-management', 'create');
    }

    public function update(PlatformUser $actor, LeadStatus $status): bool
    {
        return $actor->hasModulePermission('module-management', 'update');
    }

    public function delete(PlatformUser $actor, LeadStatus $status): bool
    {
        return $actor->hasModulePermission('module-management', 'delete');
    }
}
