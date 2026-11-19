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
        Schema::table('calling_app_devices', function (Blueprint $table) {
            // Firebase Cloud Messaging registration token the app reports
            // after pairing (and again whenever Firebase rotates it) — how an
            // incoming WhatsApp call wakes the app even when it's closed.
            // Null until the app registers one; a paired device WITH a token
            // counts as available for incoming calls (see SoftphonePresence).
            $table->string('fcm_token', 512)->nullable()->after('browser_marker_hash');
            $table->timestamp('fcm_token_updated_at')->nullable()->after('fcm_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calling_app_devices', function (Blueprint $table) {
            $table->dropColumn(['fcm_token', 'fcm_token_updated_at']);
        });
    }
};
