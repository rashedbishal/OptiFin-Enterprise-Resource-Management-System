<?php

namespace App\Policies;

use App\Models\LeadSource;
use App\Models\TenantUser;

/**
 * Lead sources are configured by the Platform Admin (see PlatformUser-side
 * authorization in Platform\Clients\LeadSources). Tenant-side access is
 * otherwise read-only, gated only by the Lead Management module/view
 * permission — `delete` is the one deliberate exception, letting a Client
 * Admin remove an empty Facebook Lead Ads source directly from the Leads
 * page (see Tenant\Leads\Index::deleteSource()) rather than needing the
 * Platform Admin. Callers are still responsible for only allowing it when
 * the source has no leads; that's a data-state check, not an authorization
 * one, so it isn't encoded here.
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

    public function delete(TenantUser $user, LeadSource $leadSource): bool
    {
        return $user->tenant_id === $leadSource->tenant_id
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'delete');
    }

    /**
     * A second deliberate mutation exception on an otherwise Platform-
     * Admin-managed/read-only model, alongside delete() above — gates
     * toggling which roles receive unassigned leads from this source (see
     * LeadSource::unassignedRoles()'s own docblock), not the source's own
     * config, which stays exclusively Platform Admin territory.
     */
    public function update(TenantUser $user, LeadSource $leadSource): bool
    {
        return $user->tenant_id === $leadSource->tenant_id
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }
}
