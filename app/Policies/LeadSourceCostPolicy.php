<?php

namespace App\Policies;

use App\Models\LeadSourceCost;
use App\Models\Tenant;
use App\Models\TenantUser;

/**
 * Source cost tracking is tenant self-service, Client-Admin-only — no new
 * permission slug exists for this (PermissionSlug is a fixed global
 * View/Create/Update/Delete enum, not extensible per module without a much
 * larger cross-cutting change), so every ability here reuses the existing
 * lead-management "update" permission + isClientAdmin(), the identical bar
 * LeadTagPolicy/LeadScoringRulePolicy/LeadCustomFieldDefinitionPolicy
 * already use. Deliberately a SEPARATE policy from LeadSourcePolicy — this
 * must never look like a Client Admin gained edit rights over LeadSource
 * itself (its Platform-Admin-owned credentials/webhook config stay exactly
 * as off-limits as before).
 */
class LeadSourceCostPolicy
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

    public function update(TenantUser $user, LeadSourceCost $cost): bool
    {
        return $this->belongsToUserTenant($user, $cost)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    public function delete(TenantUser $user, LeadSourceCost $cost): bool
    {
        return $this->belongsToUserTenant($user, $cost)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    private function belongsToUserTenant(TenantUser $user, LeadSourceCost $cost): bool
    {
        return $user->tenant_id === $cost->tenant_id;
    }
}
