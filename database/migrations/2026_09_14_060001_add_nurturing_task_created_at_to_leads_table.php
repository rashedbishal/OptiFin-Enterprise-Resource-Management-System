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
        Schema::table('leads', function (Blueprint $table) {
            // Same-day dedup marker for Feature #9's nurturing-created
            // follow-up task only — the notification action dedups against
            // the existing `notifications` table instead (see
            // NurtureInactiveLeads), so this column exists purely for the
            // task action's own idempotency check.
            $table->timestamp('nurturing_task_created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('nurturing_task_created_at');
        });
    }
};
