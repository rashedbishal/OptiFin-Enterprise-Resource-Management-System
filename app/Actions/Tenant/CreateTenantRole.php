<?php

namespace App\Actions\Tenant;

use App\Models\Role;
use App\Models\Tenant;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateTenantRole
{
    public function __construct(private readonly RoleService $roleService) {}

    /**
     * @param  array<int, string>  $moduleIds
     * @param  array<int, string>  $permissionIds
     */
    public function handle(Tenant $tenant, string $name, array $moduleIds, array $permissionIds): Role
    {
        return DB::transaction(function () use ($tenant, $name, $moduleIds, $permissionIds) {
            $role = Role::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'slug' => Str::slug($name),
            ]);

            $this->roleService->syncModules($role, $moduleIds);
            $this->roleService->syncPermissions($role, $permissionIds);

            return $role;
        });
    }
}
