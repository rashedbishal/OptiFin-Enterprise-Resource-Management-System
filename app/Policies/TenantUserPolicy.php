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

    public function update(TenantUser $actor, TenantUser $target): bool
    {
        return $actor->isClientAdmin() && $actor->tenant_id === $target->tenant_id;
    }

    public function delete(TenantUser $actor, TenantUser $target): bool
    {
        return $actor->isClientAdmin() && $actor->tenant_id === $target->tenant_id;
    }
}
