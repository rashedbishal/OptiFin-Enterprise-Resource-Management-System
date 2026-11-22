<?php

use App\Enums\MonitoringTool;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Integrations health (Admin → Monitoring → Integrations):
 *
 * - lead_source_token_checks: the latest result of the daily read-only Meta
 *   token check, one row per lead source. A separate table so the existing
 *   lead_sources data is never touched; deleted with its lead source (and so
 *   with its tenant).
 * - the "Integrations" platform module, granted per admin through their Role
 *   like the other monitoring tools.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_source_token_checks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('lead_source_id')->unique()->constrained('lead_sources')->cascadeOnDelete();
            $table->string('status');
            // Null with expiry_known = true means the token never expires.
            $table->timestamp('expires_at')->nullable();
            $table->boolean('expiry_known')->default(false);
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });

        $tool = MonitoringTool::Integrations;

        if (! DB::table('modules')->where('slug', $tool->value)->exists()) {
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
    }

    public function down(): void
    {
        $moduleIds = DB::table('modules')->where('slug', MonitoringTool::Integrations->value)->pluck('id');
        DB::table('role_module_permissions')->whereIn('module_id', $moduleIds)->delete();
        DB::table('role_modules')->whereIn('module_id', $moduleIds)->delete();
        DB::table('modules')->whereIn('id', $moduleIds)->delete();

        Schema::dropIfExists('lead_source_token_checks');
    }
};
