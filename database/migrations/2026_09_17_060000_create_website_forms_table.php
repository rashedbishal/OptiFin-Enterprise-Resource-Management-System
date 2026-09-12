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
        Schema::create('website_forms', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Tenant self-service, like LeadTag/LeadScoringRule/
            // LeadCustomFieldDefinition/LeadSourceCost — a tenant designs
            // and manages its own forms.
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Every Website Form for a tenant — legacy webhook included —
            // attributes to this ONE shared, lazily-created LeadSource of
            // type WebsiteForm (see App\Livewire\Tenant\WebsiteForms\Index
            // ::save()). restrictOnDelete: the shared source can't be
            // deleted while any form still depends on it.
            $table->foreignUlid('lead_source_id')->constrained('lead_sources')->restrictOnDelete();

            $table->string('name');

            // Only the SHA-256 hash of the public submission token is ever
            // stored — the plaintext is shown once, at creation/
            // regeneration time, and never persisted. Fast, deterministic
            // hashing (not bcrypt/argon2) is deliberate: the token itself
            // carries the entropy (Str::random(40), same convention
            // Sanctum's own personal access tokens use), so an indexed
            // equality lookup is both sufficient and what makes the public
            // submission endpoint's form resolution O(1) instead of a
            // linear scan with a slow KDF.
            $table->string('token_hash')->unique();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_forms');
    }
};
