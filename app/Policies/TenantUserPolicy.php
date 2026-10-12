<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\TenantUser;

class TenantUserPolicy
{
    public function viewAny(TenantUser $actor, Tenant $tenant): bool
    {
        return $actor->isClientAdmin() && $actor->tenant_id === $tenant->id;
    }

    public function view(TenantUser $actor, TenantUser $target): bool
    {
        return $actor->isClientAdmin() && $actor->tenant_id === $target->tenant_id;
    }

    public function create(TenantUser $actor, Tenant $tenant): bool
    {
        return $actor->isClientAdmin() && $actor->tenant_id === $tenant->id;
    }

    /**
     * A Client Admin can edit (name/email/role) and deactivate every user
     * in their tenant except themselves — their own account is
     * provisioned by Platform Admin (see CreateClient), so only Platform
     * Admin can change it (App\Actions\Platform\ManageClientAdminActivation).
     * This also covers Index::toggleActive(), which shares this same gate.
     */
    public function update(TenantUser $actor, TenantUser $target): bool
    {
        return $actor->isClientAdmin() && $actor->tenant_id === $target->tenant_id && $actor->isNot($target);
    }

    /**
     * Unconditional, unlike TenantUserObserver's own "can't delete the
     * tenant's last active owner" guard: a Client Admin can never delete
     * their own account, even when another Client Admin exists in the
     * tenant — same reasoning as update() above.
     */
    public function delete(TenantUser $actor, TenantUser $target): bool
    {
        return $actor->isClientAdmin() && $actor->tenant_id === $target->tenant_id && $actor->isNot($target);
    }
}
