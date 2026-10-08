<?php

namespace App\Policies;

use App\Models\MessengerMessageTemplate;
use App\Models\Tenant;
use App\Models\TenantUser;

/**
 * Messenger template catalog management is tenant self-service,
 * Client-Admin-only — same "reuse the existing lead-management 'update'
 * permission + isClientAdmin()" bar as WhatsAppMessageTemplatePolicy/
 * LeadTagPolicy, since no dedicated permission slug exists for this either.
 */
class MessengerMessageTemplatePolicy
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

    public function update(TenantUser $user, MessengerMessageTemplate $template): bool
    {
        return $this->belongsToUserTenant($user, $template)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    public function delete(TenantUser $user, MessengerMessageTemplate $template): bool
    {
        return $this->belongsToUserTenant($user, $template)
            && $user->isClientAdmin()
            && $user->hasModulePermission('lead-management', 'update');
    }

    private function belongsToUserTenant(TenantUser $user, MessengerMessageTemplate $template): bool
    {
        return $user->tenant_id === $template->tenant_id;
    }
}
