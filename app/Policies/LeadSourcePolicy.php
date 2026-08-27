<?php

namespace App\Policies;

use App\Models\LeadSource;
use App\Models\TenantUser;

/**
 * Lead sources are configured by the Platform Admin (see PlatformUser-side
 * authorization in Platform\Clients\LeadSources). Tenant-side access is
 * read-only, gated only by the Lead Management module/view permission.
 */
class LeadSourcePolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $user->hasModulePermission('lead-management', 'view');
    }

    public function view(TenantUser $user, LeadSource $leadSource): bool
    {
        return $user->tenant_id === $leadSource->tenant_id
            && $user->hasModulePermission('lead-management', 'view');
    }
}
