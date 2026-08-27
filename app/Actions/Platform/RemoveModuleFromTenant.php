<?php

namespace App\Actions\Platform;

use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class RemoveModuleFromTenant
{
    public function handle(Tenant $tenant, Module $module): void
    {
        DB::transaction(function () use ($tenant, $module) {
            $tenant->modules()->detach($module->id);

            // No role belonging to this tenant may keep referencing a
            // module the tenant no longer has assigned.
            foreach ($tenant->roles as $role) {
                $role->modules()->detach($module->id);
            }
        });
    }
}
