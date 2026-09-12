<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\WebsiteForm;

/**
 * Website Form management is tenant self-service, Client-Admin-only — no
 * new permission slug exists for this (PermissionSlug is a fixed global
 * View/Create/Update/Delete enum, not extensible per module without a much
 * larger cross-cutting change), so every ability here reuses the existing
 * lead-management "update" permission + isClientAdmin(), the identical bar
 * LeadTagPolicy/LeadScoringRulePolicy/LeadCustomFieldDefinitionPolicy/
 * LeadSourceCostPolicy already use.
 */
class WebsiteFormPolicy
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

    public function update(TenantUser $user, WebsiteForm $form): bool
    {
        return $this->belongsToUserTenant($user, $form)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    public function delete(TenantUser $user, WebsiteForm $form): bool
    {
        return $this->belongsToUserTenant($user, $form)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    private function belongsToUserTenant(TenantUser $user, WebsiteForm $form): bool
    {
        return $user->tenant_id === $form->tenant_id;
    }
}
