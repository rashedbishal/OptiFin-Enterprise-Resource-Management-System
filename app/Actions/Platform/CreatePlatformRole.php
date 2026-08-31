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
     * @param  array<string, array<int, string>>  $modulePermissions
     */
    public function handle(string $name, array $moduleIds, array $modulePermissions): Role
    {
        return DB::transaction(function () use ($name, $moduleIds, $modulePermissions) {
            $role = Role::create([
                'tenant_id' => null,
                'name' => $name,
                'slug' => Str::slug($name),
            ]);

            $this->roleService->syncModules($role, $moduleIds);
            $this->roleService->syncModulePermissions($role, $modulePermissions);

            return $role;
        });
    }
}
