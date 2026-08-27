<?php

namespace App\Actions\Platform;

use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePlatformRole
{
    public function __construct(private readonly RoleService $roleService) {}

    /**
     * @param  array<int, string>  $moduleIds
     * @param  array<int, string>  $permissionIds
     */
    public function handle(string $name, array $moduleIds, array $permissionIds): Role
    {
        return DB::transaction(function () use ($name, $moduleIds, $permissionIds) {
            $role = Role::create([
                'tenant_id' => null,
                'name' => $name,
                'slug' => Str::slug($name),
            ]);

            $this->roleService->syncModules($role, $moduleIds);
            $this->roleService->syncPermissions($role, $permissionIds);

            return $role;
        });
    }
}
