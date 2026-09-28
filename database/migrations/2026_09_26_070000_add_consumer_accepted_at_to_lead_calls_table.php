<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WhatsApp Outbound Calling — additive only. `consumer_accepted_at` is
     * set exactly once, only by RecordOutboundCallStatusEvent on Meta's own
     * `ACCEPTED` call-status webhook — the one authoritative signal that the
     * consumer's WhatsApp app was actually answered, independent of (and
     * required in addition to) the browser's own WebRTC/ICE confirmation.
     * Every pre-existing row is valid unchanged (defaults to NULL).
     */
    public function up(): void
    {
        Schema::table('lead_calls', function (Blueprint $table) {
            $table->timestamp('consumer_accepted_at')->nullable()->after('connected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_calls', function (Blueprint $table) {
            $table->dropColumn('consumer_accepted_at');
        });
    }
};
