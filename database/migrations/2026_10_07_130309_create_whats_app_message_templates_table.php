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
        Schema::create('whats_app_message_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Tenant self-service, like LeadTag/LeadSourceCost — this only
            // records the exact name/language/variable count of a template
            // the Client Admin already created and got approved directly in
            // Meta's own WhatsApp Manager. This app never creates, edits, or
            // submits a template to Meta (see the model's own docblock for
            // why: that needs the separate whatsapp_business_management
            // scope, which this app's stored tokens deliberately don't
            // carry for Phase 1 — only sending an already-approved one,
            // which needs nothing beyond whatsapp_business_messaging).
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // cascadeOnDelete (unlike LeadSourceCost's restrictOnDelete): a
            // deleted lead source has no stored credentials left to ever
            // send through, so a template row referencing it is pure dead
            // weight, not data worth protecting.
            $table->foreignUlid('lead_source_id')->constrained('lead_sources')->cascadeOnDelete();

            // Meta's own exact template name (lowercase/numbers/underscores
            // only, enforced at validation) — must match character-for-
            // character what Meta approved, or the send is rejected.
            $table->string('name');

            // Meta's language code, e.g. "en_US" — a template is approved
            // per language, so the same name can exist once per language.
            $table->string('language');

            // How many {{1}}, {{2}}, ... body variables this template's
            // approved content has — drives how many value inputs the
            // composer renders when sending it. Body-text variables only
            // for Phase 1, no header media/buttons (see
            // WhatsAppSender::sendTemplate()'s own docblock).
            $table->unsignedTinyInteger('variable_count')->default(0);

            // A soft "don't offer this one" switch — deactivating keeps the
            // row (and any Message rows that already snapshotted it) rather
            // than deleting, the same "never destroy history" reasoning as
            // LeadScoringRule/WebsiteForm's own is_active column.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Meta itself scopes a template's identity by (WABA, name,
            // language) — this mirrors that one level down, per lead
            // source.
            $table->unique(['lead_source_id', 'name', 'language']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whats_app_message_templates');
    }
};
