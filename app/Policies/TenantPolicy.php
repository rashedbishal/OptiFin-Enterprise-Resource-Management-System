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

    /**
     * Deliberately not permission-based like the other abilities above —
     * permanently erasing a tenant (and everything it owns, including
     * files on disk — see App\Actions\Platform\DeleteTenant) is reserved
     * for the Mother Admin specifically, never any other Platform Admin
     * no matter what client-management permissions they hold. Mother Admin
     * already bypasses this check entirely via Gate::before (see
     * AppServiceProvider::configureAuthorization()) — this is the
     * authoritative statement of intent for everyone else.
     */
    public function delete(PlatformUser $actor, Tenant $tenant): bool
    {
        return $actor->isMotherAdmin();
    }
}
