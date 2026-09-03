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
            // Record-only: never read by meetingAlertActive() or any other
            // alert/comparison logic, which stays purely date-based on
            // meeting_date. Nullable and independent of it — clearing the
            // date (cancel/mark done) also clears this, but setting a time
            // alone with no date is harmless, unused data.
            $table->time('meeting_time')->nullable()->after('meeting_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('meeting_time');
        });
    }
};
