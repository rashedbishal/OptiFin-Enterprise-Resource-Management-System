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
            // A fourth, independent alert-settings group alongside
            // meeting/follow-up/task above — same column shape, but with
            // two independent enable flags sharing one threshold (Feature
            // #9's notification and follow-up-task actions are each
            // independently toggleable, unlike the single enabled flag the
            // other three groups use).
            $table->boolean('lead_nurturing_notify_enabled')->default(false);
            $table->boolean('lead_nurturing_task_enabled')->default(false);
            $table->unsignedSmallInteger('lead_nurturing_inactive_days')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'lead_nurturing_notify_enabled',
                'lead_nurturing_task_enabled',
                'lead_nurturing_inactive_days',
            ]);
        });
    }
};
