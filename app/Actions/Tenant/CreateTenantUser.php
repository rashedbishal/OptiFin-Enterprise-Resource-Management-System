<?php

namespace App\Actions\Tenant;

use App\Enums\TenantUserType;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Validation\ValidationException;

class CreateTenantUser
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Tenant $tenant, array $data): TenantUser
    {
        $role = Role::query()->whereKey($data['role_id'])->first();

        if (! $role || $role->tenant_id !== $tenant->id) {
            throw ValidationException::withMessages([
                'role_id' => 'The selected role does not belong to this client.',
            ]);
        }

        return TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_type' => TenantUserType::ClientUser,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => $role->id,
        ]);
    }
}
