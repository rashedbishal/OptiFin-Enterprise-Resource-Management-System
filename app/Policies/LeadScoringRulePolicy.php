<?php

namespace App\Policies;

use App\Models\LeadScoringRule;
use App\Models\Tenant;
use App\Models\TenantUser;

/**
 * Lead Scoring rules are tenant self-service, Client-Admin-only — no new
 * permission slug exists for this (PermissionSlug is a fixed global
 * View/Create/Update/Delete enum, not extensible per module without a much
 * larger cross-cutting change), so every ability here reuses the existing
 * lead-management "update" permission + isClientAdmin(), the identical bar
 * LeadPolicy::merge()/assign() already use.
 */
class LeadScoringRulePolicy
{
    public function create(TenantUser $user, Tenant $tenant): bool
    {
        return $user->tenant_id === $tenant->id
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    public function update(TenantUser $user, LeadScoringRule $rule): bool
    {
        return $this->belongsToUserTenant($user, $rule)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    public function delete(TenantUser $user, LeadScoringRule $rule): bool
    {
        return $this->belongsToUserTenant($user, $rule)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    private function belongsToUserTenant(TenantUser $user, LeadScoringRule $rule): bool
    {
        return $user->tenant_id === $rule->tenant_id;
    }
}
