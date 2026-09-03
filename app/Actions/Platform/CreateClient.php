<?php

namespace App\Actions\Platform;

use App\Enums\TenantUserType;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;

/**
 * Creating a Client provisions its tenant record, a protected "Client
 * Admin" owner role, and the first Client Admin user in one transaction -
 * a tenant is never left without an owner.
 */
class CreateClient
{
    /**
     * @param  array<string, mixed>  $tenantData
     * @param  array<string, mixed>  $adminData
     */
    public function handle(array $tenantData, array $adminData, PlatformUser $createdBy): Tenant
    {
        return DB::transaction(function () use ($tenantData, $adminData, $createdBy) {
            $tenant = Tenant::create([
                ...$tenantData,
                'tenant_code' => $this->nextTenantCode(),
                'lead_id_prefix' => Tenant::generateLeadIdPrefix($tenantData['name']),
                'created_by' => $createdBy->id,
            ]);

            $ownerRole = Role::create([
                'tenant_id' => $tenant->id,
                'name' => 'Client Admin',
                'slug' => 'client-admin',
                'is_system' => true,
                'is_owner_role' => true,
            ]);

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_type' => TenantUserType::ClientAdmin,
                'name' => $adminData['name'],
                'email' => $adminData['email'],
                'password' => $adminData['password'],
                'role_id' => $ownerRole->id,
            ]);

            return $tenant;
        });
    }

    private function nextTenantCode(): string
    {
        $last = Tenant::query()->orderByDesc('tenant_code')->value('tenant_code');
        $next = $last ? ((int) substr($last, 4)) + 1 : 1;

        return sprintf('TEN-%06d', $next);
    }
}
