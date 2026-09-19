<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lead_imports', function (Blueprint $table) {
            // SHA-256 (hex) of the exact uploaded bytes. Nullable: imports
            // that predate this column are never backfilled. Deliberately a
            // plain index, not unique — a re-import of identical content is
            // allowed after explicit confirmation.
            $table->string('file_hash', 64)->nullable()->after('file_path');
            $table->index(['tenant_id', 'file_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_imports', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'file_hash']);
            $table->dropColumn('file_hash');
        });
    }
};
