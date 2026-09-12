<?php

namespace App\Policies;

use App\Models\LeadTag;
use App\Models\Tenant;
use App\Models\TenantUser;

/**
 * Lead tag catalog management is tenant self-service, Client-Admin-only —
 * no new permission slug exists for this (PermissionSlug is a fixed global
 * View/Create/Update/Delete enum, not extensible per module without a much
 * larger cross-cutting change), so every ability here reuses the existing
 * lead-management "update" permission + isClientAdmin(), the identical bar
 * LeadScoringRulePolicy/LeadPolicy::merge()/assign() already use.
 *
 * Attaching/detaching a tag on a specific lead is deliberately NOT gated by
 * this policy — that reuses LeadPolicy::update() as-is (admin, or the
 * assigned Client User), the same lightweight bar adding a LeadNote uses,
 * since tagging a lead you already have update access to is a day-to-day
 * action, not a catalog-management one.
 */
class LeadTagPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $user->isClientAdmin() && $user->hasModulePermission('lead-management', 'update');
    }

    public function create(TenantUser $user, Tenant $tenant): bool
    {
        return $user->tenant_id === $tenant->id
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    public function update(TenantUser $user, LeadTag $tag): bool
    {
        return $this->belongsToUserTenant($user, $tag)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    public function delete(TenantUser $user, LeadTag $tag): bool
    {
        return $this->belongsToUserTenant($user, $tag)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    private function belongsToUserTenant(TenantUser $user, LeadTag $tag): bool
    {
        return $user->tenant_id === $tag->tenant_id;
    }
}
