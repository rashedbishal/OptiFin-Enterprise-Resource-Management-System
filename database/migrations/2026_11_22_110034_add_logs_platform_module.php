<?php

use App\Enums\MonitoringTool;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The "Logs" platform module (Admin → Monitoring → Logs, the log viewer),
 * granted per admin through their Role like the other monitoring tools.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tool = MonitoringTool::Logs;

        if (DB::table('modules')->where('slug', $tool->value)->exists()) {
            return;
        }

        DB::table('modules')->insert([
            'id' => (string) Str::ulid(),
            'name' => $tool->label(),
            'slug' => $tool->value,
            'description' => $tool->description(),
            'scope' => 'platform',
            'is_system' => true,
            'status' => 'active',
            'sort_order' => (int) DB::table('modules')->where('scope', 'platform')->max('sort_order') + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $moduleIds = DB::table('modules')->where('slug', MonitoringTool::Logs->value)->pluck('id');
        DB::table('role_module_permissions')->whereIn('module_id', $moduleIds)->delete();
        DB::table('role_modules')->whereIn('module_id', $moduleIds)->delete();
        DB::table('modules')->whereIn('id', $moduleIds)->delete();
    }
};
