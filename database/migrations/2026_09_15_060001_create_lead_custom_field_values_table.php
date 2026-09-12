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
        Schema::create('lead_custom_field_values', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Denormalized, same discipline as LeadDuplicateLink/Deal/Task —
            // every query in this module filters tenant_id explicitly rather
            // than relying on a global scope, so it's stored directly here
            // too rather than always joining through lead_id/definition_id.
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();

            // restrictOnDelete (not cascade): a definition with any existing
            // value must never be deletable out from under it — the
            // application-level check in
            // LeadCustomFieldDefinitionPolicy/Livewire delete() is what
            // produces a friendly error; this is the DB-level backstop.
            $table->foreignUlid('lead_custom_field_definition_id')
                ->constrained('lead_custom_field_definitions')
                ->restrictOnDelete();

            // A single nullable text column regardless of field type —
            // type-specific interpretation (numeric/date formatting, select
            // option display) happens at the presentation/validation layer,
            // not the schema layer (same reasoning as LeadScoringRule::value
            // being a single JSON-cast column rather than several typed
            // ones). No row is ever stored for a blank value — clearing a
            // field's value deletes its row instead (see
            // SaveLeadCustomFieldValues), so this column is never an empty
            // string, only a real value or absent entirely.
            $table->text('value')->nullable();

            $table->timestamps();

            // One value row per lead per field definition — the real
            // data-integrity backstop behind SaveLeadCustomFieldValues'
            // own upsert-by-definition logic.
            $table->unique(['lead_id', 'lead_custom_field_definition_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_custom_field_values');
    }
};
