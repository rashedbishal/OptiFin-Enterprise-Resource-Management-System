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
            // SHA-256 (hex) of a random marker the PAIRING browser keeps in its
            // own localStorage. The softphone compares the two to know it is
            // running on the paired phone itself and should hand calls to the
            // app — Chrome's own installed-app detection proved unreliable.
            // Only a hash is stored; null for a pairing made before this
            // existed (that phone falls back to browser calling until it is
            // reconnected).
            $table->string('browser_marker_hash', 64)->nullable()->after('device_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calling_app_devices', function (Blueprint $table) {
            $table->dropColumn('browser_marker_hash');
        });
    }
};
