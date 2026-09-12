<?php

use App\Enums\ConsentType;
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
        Schema::table('website_form_fields', function (Blueprint $table) {
            // Feature #14 — set only when field_type = consent (a third
            // WebsiteFormFieldType case alongside fixed/custom). NULL for
            // every existing fixed/custom row, so the new unique constraint
            // below never collides with them (same NULL-never-collides
            // reasoning the fixed_field/lead_custom_field_definition_id
            // constraints already rely on).
            $table->enum('consent_type', array_column(ConsentType::cases(), 'value'))->nullable()->after('lead_custom_field_definition_id');

            // A genuinely new concept: today no WebsiteFormField row has an
            // optional/required toggle at all (a fixed field is always
            // hardcoded-nullable in ValidateWebsiteFormSubmission; a custom
            // field borrows its LeadCustomFieldDefinition's own
            // is_required). Scoped so it only changes behavior for the new
            // Consent field type — default false is a no-op for every
            // existing fixed/custom row, since neither reads this column.
            $table->boolean('is_required')->default(false)->after('consent_type');

            // Tenant-defined free text, meaningful only for a Consent field
            // — snapshotted onto every LeadConsent/LeadConsentEvent this
            // specific field produces, so "what policy version were they
            // shown" survives even if the tenant later changes it here.
            $table->string('policy_version')->nullable()->after('is_required');
            $table->string('policy_url')->nullable()->after('policy_version');

            // NULL never collides with NULL, so this only actually
            // constrains two rows that are both field_type = consent on the
            // same form — a form can't configure the same consent type
            // twice, mirroring the existing fixed_field/
            // lead_custom_field_definition_id constraints exactly.
            $table->unique(['website_form_id', 'consent_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('website_form_fields', function (Blueprint $table) {
            $table->dropUnique(['website_form_id', 'consent_type']);
            $table->dropColumn(['consent_type', 'is_required', 'policy_version', 'policy_url']);
        });
    }
};
