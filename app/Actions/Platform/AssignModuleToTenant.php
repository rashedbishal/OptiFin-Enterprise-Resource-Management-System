<?php

namespace App\Actions\Platform;

use App\Models\LeadStatus;
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

            if ($module->slug === 'lead-management') {
                $this->seedDefaultPipeline($tenant);
            }
        });
    }

    /**
     * Gives a tenant the full current status catalog as its pipeline the
     * first time Lead Management is activated for it — a tenant needs at
     * least one configured status before it can create a lead at all, and
     * without this a freshly activated tenant would have none. Only seeds
     * once: if the tenant already has a pipeline (activated before,
     * deactivated, now reactivated), its Platform-Admin-configured order is
     * left untouched rather than reset back to the full catalog.
     */
    private function seedDefaultPipeline(Tenant $tenant): void
    {
        if ($tenant->leadStatuses()->exists()) {
            return;
        }

        $statusIds = LeadStatus::query()->orderBy('sort_order')->pluck('id');

        $tenant->leadStatuses()->attach(
            $statusIds->mapWithKeys(fn (string $id, int $index): array => [$id => ['position' => $index + 1]])->all()
        );
    }
}
