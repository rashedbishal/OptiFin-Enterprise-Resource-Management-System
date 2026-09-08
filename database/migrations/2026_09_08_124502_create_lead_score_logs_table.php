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
        Schema::create('lead_score_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->integer('score');

            // Pre-clamp sum, kept alongside the clamped `score` so the
            // breakdown UI can show "your rules total 145, capped to 100"
            // rather than hiding the overage. See LeadScoringService.
            $table->integer('raw_total');

            // A point-in-time SNAPSHOT of every matched rule (name, field,
            // operator, configured value, the lead's actual matched value,
            // points) — not a live join to lead_scoring_rules, so a log
            // stays fully readable after a rule is later edited or deleted.
            $table->json('breakdown');

            // Short reason this computation ran: lead_received,
            // status_priority_changed, detail_updated, merge,
            // scheduled_sweep, or manual. Not part of the "did anything
            // change" comparison in LeadScoringService::logIfChanged() —
            // only score/breakdown are.
            $table->string('trigger');

            // Only ever set when trigger = 'manual' — who clicked
            // "Recalculate" on the lead detail page.
            $table->foreignUlid('triggered_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            $table->timestamp('computed_at');

            $table->timestamps();

            $table->index(['lead_id', 'computed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_score_logs');
    }
};
