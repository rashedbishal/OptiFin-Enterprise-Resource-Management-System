<?php

namespace App\Actions\Platform;

use App\Models\Module;
use App\Models\Permission;
use App\Models\PlatformUser;
use App\Models\RoleModulePermission;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Assigning a module to a tenant also grants it, with full permissions, to
 * the tenant's protected owner role - so a Client Admin never has to
 * manually re-grant itself access to a module Mother Admin just gave it.
 */
class AssignModuleToTenant
{
    public function handle(Tenant $tenant, Module $module, PlatformUser $assignedBy): void
    {
        DB::transaction(function () use ($tenant, $module, $assignedBy) {
            $tenant->modules()->syncWithoutDetaching([
                $module->id => [
                    'is_active' => true,
                    'assigned_by' => $assignedBy->id,
                    'assigned_at' => now(),
                ],
            ]);

            $ownerRole = $tenant->ownerRole();

            if ($ownerRole) {
                $ownerRole->modules()->syncWithoutDetaching([$module->id]);

                $ownerRole->modulePermissions()->where('module_id', $module->id)->delete();

                RoleModulePermission::query()->insert(
                    Permission::query()->pluck('id')->map(fn (string $permissionId) => [
                        'role_id' => $ownerRole->id,
                        'module_id' => $module->id,
                        'permission_id' => $permissionId,
                    ])->all()
                );
            }
        });
    }
}
