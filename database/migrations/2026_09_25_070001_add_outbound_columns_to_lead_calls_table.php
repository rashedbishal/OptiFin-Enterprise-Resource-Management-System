<?php

use App\Enums\CallDirection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WhatsApp Outbound Calling — additive only, every existing inbound row
     * is valid with no data rewrite (direction defaults 'inbound' for all
     * of them).
     *
     * external_call_id relaxed to nullable: an outbound row is created
     * (status Initiated) before Meta's synchronous /calls response returns
     * the wacid — see App\Actions\Tenant\InitiateOutboundCall. This does
     * NOT weaken the existing (lead_source_id, external_call_id) uniqueness
     * for any populated value: Postgres (and SQLite, the test driver)
     * treat every NULL as distinct from every other NULL in a unique index,
     * so multiple Initiated rows with a still-unknown call id never
     * conflict, and every inbound row (never NULL, RecordInboundCall always
     * knows the wacid before inserting) is completely unaffected.
     *
     * biz_opaque_callback_data: the server-minted correlation token used
     * for the brief pre-wacid window and, after that, as a defense-in-depth
     * secondary check alongside external_call_id — never accepted from the
     * browser, never a call_permission_reply correlation key (that webhook
     * has no call-specific id at all — see App\Actions\Tenant\
     * RecordCallPermissionReply).
     */
    public function up(): void
    {
        Schema::table('lead_calls', function (Blueprint $table) {
            $table->string('direction', 16)->default(CallDirection::Inbound->value)->after('lead_source_id');
            $table->string('biz_opaque_callback_data', 64)->nullable()->after('external_call_id');
        });

        Schema::table('lead_calls', function (Blueprint $table) {
            $table->string('external_call_id', 191)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_calls', function (Blueprint $table) {
            $table->string('external_call_id', 191)->nullable(false)->change();
        });

        Schema::table('lead_calls', function (Blueprint $table) {
            $table->dropColumn(['direction', 'biz_opaque_callback_data']);
        });
    }
};
