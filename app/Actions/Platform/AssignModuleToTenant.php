<?php

namespace App\Actions\Platform;

use App\Models\Module;
use App\Models\Permission;
use App\Models\PlatformUser;
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
                $ownerRole->permissions()->syncWithoutDetaching(
                    Permission::query()->pluck('id')
                );
            }
        });
    }
}
