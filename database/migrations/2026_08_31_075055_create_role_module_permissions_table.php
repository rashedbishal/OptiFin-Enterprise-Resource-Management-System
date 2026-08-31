<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('role_module_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUlid('module_id')->constrained('modules')->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->unique(['role_id', 'module_id', 'permission_id']);
        });

        // A role's permissions used to be flat and applied uniformly to
        // every module it had. Expand that into explicit per-module grants
        // before role_permissions is dropped, so existing roles keep
        // exactly the access they had before this migration.
        $grants = DB::table('role_modules')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'role_modules.role_id')
            ->select('role_modules.role_id', 'role_modules.module_id', 'role_permissions.permission_id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        foreach (array_chunk($grants, 500) as $chunk) {
            DB::table('role_module_permissions')->insertOrIgnore($chunk);
        }

        Schema::dropIfExists('role_permissions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->unique(['role_id', 'permission_id']);
        });

        $grants = DB::table('role_module_permissions')
            ->select('role_id', 'permission_id')
            ->distinct()
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        foreach (array_chunk($grants, 500) as $chunk) {
            DB::table('role_permissions')->insertOrIgnore($chunk);
        }

        Schema::dropIfExists('role_module_permissions');
    }
};
