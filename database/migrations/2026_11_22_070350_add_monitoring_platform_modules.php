<?php

use App\Enums\MonitoringTool;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the Horizon / Health / Pulse monitoring tools as platform-scoped
 * system modules, so the Mother Admin can grant each one per admin through
 * the Roles page. A data migration (not only ModuleSeeder) because the
 * seeder isn't run on deploy; upserting by slug makes it safe to re-run and
 * harmless alongside the seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sortOrder = (int) DB::table('modules')->where('scope', 'platform')->max('sort_order');

        foreach (MonitoringTool::cases() as $tool) {
            $sortOrder++;

            if (DB::table('modules')->where('slug', $tool->value)->exists()) {
                DB::table('modules')->where('slug', $tool->value)->update([
                    'name' => $tool->label(),
                    'description' => $tool->description(),
                    'scope' => 'platform',
                    'is_system' => true,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('modules')->insert([
                'id' => (string) Str::ulid(),
                'name' => $tool->label(),
                'slug' => $tool->value,
                'description' => $tool->description(),
                'scope' => 'platform',
                'is_system' => true,
                'status' => 'active',
                'sort_order' => $sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $slugs = array_map(fn (MonitoringTool $tool): string => $tool->value, MonitoringTool::cases());
        $moduleIds = DB::table('modules')->whereIn('slug', $slugs)->pluck('id');

        DB::table('role_module_permissions')->whereIn('module_id', $moduleIds)->delete();
        DB::table('role_modules')->whereIn('module_id', $moduleIds)->delete();
        DB::table('modules')->whereIn('id', $moduleIds)->delete();
    }
};
