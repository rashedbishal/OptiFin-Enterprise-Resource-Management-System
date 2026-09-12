<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature #8's reporting queries (volume, daily breakdown, status, source,
 * and assignment distribution) all filter by tenant_id + a created_at range
 * — no existing index serves that composite, the same reasoning as the
 * tenant_id+assigned_to index added for the assignment-scope query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
        });
    }
};
