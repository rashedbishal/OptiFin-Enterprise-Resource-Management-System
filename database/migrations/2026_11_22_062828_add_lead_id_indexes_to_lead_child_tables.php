<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL never indexes a foreign-key column on its own, and every one of
 * these columns had no index in the live schema (only the primary key):
 *
 * - The lead detail page's activity() feed reads lead_status_logs,
 *   lead_assignments, lead_notes and lead_detail_update_logs by lead_id on
 *   every view.
 * - LeadScoringField::daysSinceLastStatusChange() reads the latest
 *   lead_status_logs row per lead in both daily sweeps
 *   (leads:recalculate-scores, leads:nurture-inactive-leads).
 * - Deleting a lead cascades into every lead_id child table.
 * - leads.source_id is read for per-source lists and the "does this source
 *   still have leads" deletion guards; leads.merged_into_lead_id backs the
 *   merged-lead redirect/alias lookups and its own self-FK null-out.
 *
 * Without these, each of those lookups is a full scan of the child table.
 */
return new class extends Migration
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $indexes = [
        'lead_status_logs' => ['lead_id', 'created_at'],
        'lead_assignments' => ['lead_id', 'created_at'],
        'lead_notes' => ['lead_id', 'created_at'],
        'lead_detail_update_logs' => ['lead_id', 'created_at'],
        'lead_consent_events' => ['lead_id', 'created_at'],
        'messages' => ['lead_id', 'created_at'],
        'conversations' => ['lead_id'],
        'lead_calls' => ['lead_id'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->index($columns);
            });
        }

        Schema::table('leads', function (Blueprint $table): void {
            $table->index('source_id');
            $table->index('merged_into_lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['source_id']);
            $table->dropIndex(['merged_into_lead_id']);
        });

        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropIndex($columns);
            });
        }
    }
};
