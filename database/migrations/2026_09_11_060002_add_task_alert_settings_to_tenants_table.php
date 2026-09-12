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
        Schema::table('tenants', function (Blueprint $table) {
            // A third, independent alert pair — deliberately NOT reused from
            // meeting_alert_enabled/followup_alert_enabled (a tenant may
            // want a different lead time for task due dates than for
            // meetings/follow-ups). Same column shape as those two.
            $table->boolean('task_alert_enabled')->default(false);
            $table->unsignedSmallInteger('task_alert_days_before')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['task_alert_enabled', 'task_alert_days_before']);
        });
    }
};
