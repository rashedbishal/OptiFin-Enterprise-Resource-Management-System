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
            // WhatsApp inbound calling — an optional tenant-wide custom
            // ringtone (MP3, <=1MB), Client-Admin-managed. Stored on the
            // `local` (private) disk, never public — see
            // App\Http\Controllers\WhatsAppCallRingtoneController, which
            // serves it only to an authenticated user of this same tenant.
            // All three are null together (no ringtone set, falls back to
            // the synthesized tone) or set together (uploading always
            // replaces the prior file — see
            // CallingSettings\Index::save()).
            $table->string('whatsapp_call_ringtone_path')->nullable()->after('lead_nurturing_inactive_days');
            $table->string('whatsapp_call_ringtone_original_name')->nullable()->after('whatsapp_call_ringtone_path');
            $table->unsignedInteger('whatsapp_call_ringtone_size')->nullable()->after('whatsapp_call_ringtone_original_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_call_ringtone_path', 'whatsapp_call_ringtone_original_name', 'whatsapp_call_ringtone_size']);
        });
    }
};
