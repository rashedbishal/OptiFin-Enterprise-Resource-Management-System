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
        Schema::table('leads', function (Blueprint $table) {
            // Nullable and left untouched on every existing lead until
            // App\Services\LeadScoringService first computes for it. Stays
            // null forever for a tenant with zero active
            // LeadScoringRule rows — see LeadScoringService::computeFor() —
            // distinguishing "not scored" from a real computed 0.
            $table->integer('lead_score')->nullable()->after('win_probability');
            $table->timestamp('lead_score_computed_at')->nullable()->after('lead_score');

            $table->index(['tenant_id', 'lead_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'lead_score']);
            $table->dropColumn(['lead_score', 'lead_score_computed_at']);
        });
    }
};
