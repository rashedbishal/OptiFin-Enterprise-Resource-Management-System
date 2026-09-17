<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB-1 — lead_consents must survive a hard Lead delete (Leads have no
     * SoftDeletes and never will), so its lead_id FK moves from
     * cascadeOnDelete to nullOnDelete and gains a point-in-time identity
     * snapshot (lead_identifier/lead_name) so a detached row — lead_id NULL
     * — stays identifiable. Populated only by
     * App\Actions\Tenant\RecordLeadDeletionAudit at deletion time, never
     * during ordinary grant/withdraw (see App\Actions\Tenant\
     * RecordLeadConsent, left untouched).
     *
     * Order matters for both Postgres and SQLite: drop the existing FK
     * constraint before relaxing nullability, then add the new FK with the
     * new ON DELETE behavior — same sequencing already used by
     * database/migrations/2026_09_02_000000_convert_lead_status_to_configurable_catalog.php
     * for its own FK/column changes on this same `leads` graph.
     */
    public function up(): void
    {
        Schema::table('lead_consents', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });

        Schema::table('lead_consents', function (Blueprint $table) {
            $table->foreignUlid('lead_id')->nullable()->change();
        });

        Schema::table('lead_consents', function (Blueprint $table) {
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
        Schema::table('lead_consents', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropColumn(['lead_identifier', 'lead_name']);
        });

        Schema::table('lead_consents', function (Blueprint $table) {
            $table->foreignUlid('lead_id')->nullable(false)->change();
        });

        Schema::table('lead_consents', function (Blueprint $table) {
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });
    }
};
