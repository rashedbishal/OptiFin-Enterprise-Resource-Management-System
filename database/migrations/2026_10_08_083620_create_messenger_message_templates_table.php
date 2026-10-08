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
        Schema::create('messenger_message_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Parallel to whats_app_message_templates (same tenant
            // self-service "register what you already created and got
            // approved in Meta's own tools" shape) rather than a shared
            // model — the two channels' Meta-side mechanics genuinely
            // differ (Messenger Utility Message Templates, Meta's Feb 2026
            // replacement for the deprecated Message Tags feature, needs
            // its own page_utility_messaging OAuth scope and a different
            // send payload shape; see WhatsAppMessageTemplate's docblock
            // for the WhatsApp side).
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->foreignUlid('lead_source_id')->constrained('lead_sources')->cascadeOnDelete();

            // Meta's own exact template name for this Page.
            $table->string('name');

            // Meta's language code for this template, e.g. "en" — note
            // Messenger's own template-creation examples use the shorter
            // "en" form, not WhatsApp's "en_US" style; still just an opaque
            // string here, whatever Meta actually approved it under.
            $table->string('language');

            // How many {{1}}, {{2}}, ... positional body variables this
            // template's approved content has — same Phase 1 scope as
            // WhatsApp (positional only, no named parameters, no header
            // media/buttons).
            $table->unsignedTinyInteger('variable_count')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Meta scopes a Messenger template's identity by (Page, name,
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
        Schema::dropIfExists('messenger_message_templates');
    }
};
