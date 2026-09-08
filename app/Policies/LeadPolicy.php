<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\TenantUser;

class LeadPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $user->hasModulePermission('lead-management', 'view');
    }

    public function create(TenantUser $user): bool
    {
        return $user->hasModulePermission('lead-management', 'create');
    }

    public function view(TenantUser $user, Lead $lead): bool
    {
        return $this->belongsToUserTenant($user, $lead)
            && $user->hasModulePermission('lead-management', 'view')
            && ($user->isClientAdmin() || $lead->assigned_to === $user->id);
    }

    public function update(TenantUser $user, Lead $lead): bool
    {
        return $this->belongsToUserTenant($user, $lead)
            && $user->hasModulePermission('lead-management', 'update')
            && ($user->isClientAdmin() || $lead->assigned_to === $user->id);
    }

    public function delete(TenantUser $user, Lead $lead): bool
    {
        return $this->belongsToUserTenant($user, $lead)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'delete');
    }

    public function assign(TenantUser $user, Lead $lead): bool
    {
        return $this->belongsToUserTenant($user, $lead)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    /**
     * Gates both merging a possible duplicate and dismissing it as "not a
     * duplicate" — same consequential-change bar as assign()/delete(),
     * Client-Admin-only.
     */
    public function merge(TenantUser $user, Lead $lead): bool
    {
        return $this->belongsToUserTenant($user, $lead)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    private function belongsToUserTenant(TenantUser $user, Lead $lead): bool
    {
        return $user->tenant_id === $lead->tenant_id;
    }
}
