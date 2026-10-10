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
            && ($user->isClientAdmin() || $lead->assigned_to === $user->id || $this->hasUnassignedAccess($user, $lead));
    }

    public function update(TenantUser $user, Lead $lead): bool
    {
        return $this->belongsToUserTenant($user, $lead)
            && $user->hasModulePermission('lead-management', 'update')
            && ($user->isClientAdmin() || $lead->assigned_to === $user->id || $this->hasUnassignedAccess($user, $lead));
    }

    /**
     * A Client User whose role has been granted "receive unassigned leads"
     * access (see LeadSource::unassignedRoles()) can view/work an
     * UNassigned lead from a qualifying source — never a once-assigned
     * lead that later happens to belong to that source, and never a
     * substitute for assign(), which stays Client-Admin-only below: this
     * never lets a Client User claim ownership, only see and act on a lead
     * nobody owns yet. Mirrors Concerns\FiltersLeadsQuery::
     * applyAssignmentScope()'s own condition so a lead that scope surfaces
     * in a list is never one this policy then refuses to open.
     */
    private function hasUnassignedAccess(TenantUser $user, Lead $lead): bool
    {
        if ($lead->assigned_to !== null) {
            return false;
        }

        return $user->role->unassignedLeadSources()->whereKey($lead->source_id)->exists();
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
