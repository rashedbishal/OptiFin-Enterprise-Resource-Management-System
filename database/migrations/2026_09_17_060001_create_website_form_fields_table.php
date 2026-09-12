<?php

use App\Enums\WebsiteFormFieldType;
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
        Schema::create('website_form_fields', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Denormalized, same discipline as LeadCustomFieldValue/
            // LeadSourceCost — every query in this module filters tenant_id
            // explicitly rather than relying on a global scope.
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // A field-configuration row is not data — deleting the form it
            // belongs to safely cascades, unlike a LeadCustomFieldValue.
            $table->foreignUlid('website_form_id')->constrained('website_forms')->cascadeOnDelete();

            $table->enum('field_type', array_column(WebsiteFormFieldType::cases(), 'value'));

            // Set when field_type = fixed (one of email|phone|message —
            // `name` is always implicit and never has its own row here).
            $table->string('fixed_field')->nullable();

            // Set when field_type = custom. restrictOnDelete: a Custom
            // Field Definition referenced by any form (Feature #13) cannot
            // be deleted, even with zero submitted values yet — see the
            // added guard in
            // App\Livewire\Tenant\LeadCustomFields\Index::delete().
            $table->foreignUlid('lead_custom_field_definition_id')
                ->nullable()
                ->constrained('lead_custom_field_definitions')
                ->restrictOnDelete();

            // Admin config UI display order only — this app never renders
            // the public form itself, so this has no runtime/validation
            // significance.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // A NULL fixed_field/lead_custom_field_definition_id column
            // (whichever field_type this row ISN'T) never collides under
            // standard SQL unique-index semantics — these two constraints
            // only ever actually fire between two rows of the same
            // field_type on the same form.
            $table->unique(['website_form_id', 'fixed_field']);
            $table->unique(['website_form_id', 'lead_custom_field_definition_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_form_fields');
    }
};
