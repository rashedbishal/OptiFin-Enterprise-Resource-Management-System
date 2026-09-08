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
        Schema::create('lead_scoring_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Tenant self-service, not a Platform-Admin-managed shared
            // catalog like LeadStatus/Currency — a tenant's own business
            // judgement about what makes a lead valuable, not an
            // industry-standard vocabulary curated centrally. Managed by a
            // Client Admin, same permission pattern as LeadPolicy::merge()
            // (reuses the existing "update" module permission, no new slug).
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');

            // Backed by App\Enums\LeadScoringField/LeadScoringOperator —
            // plain strings rather than DB enums so a future field/operator
            // addition never needs a migration, only a new enum case.
            $table->string('field');
            $table->string('operator');

            // Scalar or array (json), so both "= urgent" and a future
            // "in [urgent, high]" fit without a schema change.
            $table->json('value');

            $table->integer('points');
            $table->boolean('is_active')->default(true);

            // Display ordering only for the rule-management UI and the
            // score breakdown's presentation order — never affects
            // evaluation, which sums every active matching rule regardless
            // of order. See App\Services\LeadScoringService.
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_scoring_rules');
    }
};
