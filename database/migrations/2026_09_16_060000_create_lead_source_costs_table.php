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
        Schema::create('lead_source_costs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Tenant self-service, like LeadTag/LeadScoringRule/
            // LeadCustomFieldDefinition — this is the tenant's own recorded
            // marketing spend, not Platform-Admin-configured source
            // infrastructure (credentials/webhook state stay on
            // lead_sources itself, untouched by this table).
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // restrictOnDelete (not cascade): a source with recorded cost
            // history must not be deletable out from under it — see the
            // added guard in App\Livewire\Tenant\Leads\Index::deleteSource().
            $table->foreignUlid('lead_source_id')->constrained('lead_sources')->restrictOnDelete();

            // Always normalized to the 1st of the calendar month it
            // represents (e.g. 2026-09-01) — Phase 1 tracks cost at
            // calendar-month granularity only, no custom ranges.
            $table->date('month');

            $table->decimal('amount', 12, 2);

            // Every cost entry for the same lead_source_id must share the
            // same currency_id (enforced in
            // App\Livewire\Tenant\LeadSourceCosts\Index::save(), not at the
            // schema level) — Phase 1 never blends or converts currencies.
            $table->foreignUlid('currency_id')->constrained('currencies');

            $table->timestamps();

            // One entry per source per month — a second entry for the same
            // month edits the existing row instead of creating a new one.
            $table->unique(['tenant_id', 'lead_source_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_source_costs');
    }
};
