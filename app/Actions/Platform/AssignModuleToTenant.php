<?php

namespace App\Actions\Platform;

use App\Models\Currency;
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
                $this->seedDefaultCurrency($tenant);
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

    /**
     * A tenant needs at least one enabled currency before it can create a
     * Lead Source at all (LeadSources::create()/save() refuse otherwise —
     * see App\Livewire\Platform\Clients\LeadSources), so a freshly
     * activated tenant with zero currencies selected would be stuck. Picks
     * the oldest catalog entry as a reasonable default; the Platform Admin
     * can add/remove currencies afterward on the client's Lead Sources
     * page. Only seeds once, same reasoning as seedDefaultPipeline(): a
     * tenant reactivating the module keeps whatever it already has.
     */
    private function seedDefaultCurrency(Tenant $tenant): void
    {
        if ($tenant->currencies()->exists()) {
            return;
        }

        $currency = Currency::query()->oldest()->first();

        if ($currency) {
            $tenant->currencies()->attach($currency->id);
        }
    }
}
