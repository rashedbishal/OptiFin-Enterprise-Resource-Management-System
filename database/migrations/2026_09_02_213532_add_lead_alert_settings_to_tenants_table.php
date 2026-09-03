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
            $table->boolean('meeting_alert_enabled')->default(false);
            $table->unsignedSmallInteger('meeting_alert_days_before')->default(0);
            $table->boolean('followup_alert_enabled')->default(false);
            $table->unsignedSmallInteger('followup_alert_days_before')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'meeting_alert_enabled',
                'meeting_alert_days_before',
                'followup_alert_enabled',
                'followup_alert_days_before',
            ]);
        });
    }
};
