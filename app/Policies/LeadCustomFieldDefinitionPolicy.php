<?php

namespace App\Policies;

use App\Models\LeadCustomFieldDefinition;
use App\Models\Tenant;
use App\Models\TenantUser;

/**
 * Custom field catalog management is tenant self-service, Client-Admin-only
 * — no new permission slug exists for this (PermissionSlug is a fixed
 * global View/Create/Update/Delete enum, not extensible per module without a
 * much larger cross-cutting change), so every ability here reuses the
 * existing lead-management "update" permission + isClientAdmin(), the
 * identical bar LeadTagPolicy/LeadScoringRulePolicy already use.
 *
 * Filling in / editing a custom field's value on a specific lead is
 * deliberately NOT gated by this policy — that reuses LeadPolicy::update()
 * as-is (admin, or the lead's assigned Client User), the same lightweight
 * bar adding a LeadNote or a Feature #3 tag uses.
 */
class LeadCustomFieldDefinitionPolicy
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

    public function update(TenantUser $user, LeadCustomFieldDefinition $definition): bool
    {
        return $this->belongsToUserTenant($user, $definition)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    public function delete(TenantUser $user, LeadCustomFieldDefinition $definition): bool
    {
        return $this->belongsToUserTenant($user, $definition)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    private function belongsToUserTenant(TenantUser $user, LeadCustomFieldDefinition $definition): bool
    {
        return $user->tenant_id === $definition->tenant_id;
    }
}
