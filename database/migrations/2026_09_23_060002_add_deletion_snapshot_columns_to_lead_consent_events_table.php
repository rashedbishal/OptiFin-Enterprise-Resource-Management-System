<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB-1 — same detach-not-destroy treatment as
     * 2026_09_23_060001_add_deletion_snapshot_columns_to_lead_consents_table.php,
     * applied to the append-only history table: lead_id moves from
     * cascadeOnDelete to nullOnDelete and gains the same lead_identifier/
     * lead_name snapshot, populated only at deletion time by
     * App\Actions\Tenant\RecordLeadDeletionAudit.
     */
    public function up(): void
    {
        Schema::table('lead_consent_events', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });

        Schema::table('lead_consent_events', function (Blueprint $table) {
            $table->foreignUlid('lead_id')->nullable()->change();
        });

        Schema::table('lead_consent_events', function (Blueprint $table) {
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();

            $table->string('lead_identifier')->nullable()->after('lead_id');
            $table->string('lead_name')->nullable()->after('lead_identifier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_consent_events', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropColumn(['lead_identifier', 'lead_name']);
        });

        Schema::table('lead_consent_events', function (Blueprint $table) {
            $table->foreignUlid('lead_id')->nullable(false)->change();
        });

        Schema::table('lead_consent_events', function (Blueprint $table) {
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });
    }
};
