<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WhatsApp inbound calling, Build Step 1 — real columns rather than
     * keys inside the encrypted `config` blob, so the platform admin's
     * source form (which rebuilds config from its own field list) can never
     * silently drop them. calling_enabled is OUR intent (default off — no
     * existing source changes behavior); calling_meta_status is the last
     * status Meta was observed/checked to have (ENABLED|DISABLED, verbatim),
     * and calling_meta_checked_at when.
     */
    public function up(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->boolean('calling_enabled')->default(false);
            $table->string('calling_meta_status', 16)->nullable();
            $table->timestamp('calling_meta_checked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_sources', function (Blueprint $table) {
            $table->dropColumn(['calling_enabled', 'calling_meta_status', 'calling_meta_checked_at']);
        });
    }
};
