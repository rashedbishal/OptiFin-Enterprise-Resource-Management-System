<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WhatsApp Call Backup / Overflow Receiver — the server-trusted signal
     * that a TenantUser's softphone tab is actually open right now, written
     * only by App\Actions\Tenant\TouchSoftphonePresence (a side effect of the
     * existing GET calls/active poll the softphone already sends every
     * `calling.softphone_heartbeat_seconds`, never a new endpoint or a
     * browser-supplied value). Read only by App\Services\SoftphonePresence,
     * which App\Services\CallRouter consults for the lead's assignee before
     * choosing routing_mode=Assignee — see CallRouter's own docblock.
     *
     * Nullable, default null: every existing row and every row created by a
     * user who has never opened the softphone reads as null, which
     * SoftphonePresence treats as available (backward compatible — see its
     * own docblock) rather than instantly routing every pre-existing
     * assignment to backups the moment this column exists.
     */
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->timestamp('softphone_last_seen_at')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('softphone_last_seen_at');
        });
    }
};
