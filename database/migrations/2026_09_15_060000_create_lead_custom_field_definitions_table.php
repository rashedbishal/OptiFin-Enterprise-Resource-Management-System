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
        Schema::create('lead_custom_field_definitions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Tenant self-service, like LeadTag/LeadScoringRule — not a
            // Platform-Admin-managed shared catalog like LeadStatus/Currency.
            // A custom field is a tenant's own business-specific data slot,
            // with no cross-tenant consistency need, managed by a Client
            // Admin via the existing "update" module permission (see
            // App\Policies\LeadCustomFieldDefinitionPolicy) — no new
            // PermissionSlug.
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');

            // Derived from name via Str::slug() at save time, same
            // technique/uniqueness discipline as LeadTag::slug.
            $table->string('key');

            // LeadCustomFieldType-backed: text|number|date|select. Plain
            // string column, not a DB enum, so a future type addition never
            // needs a migration (same reasoning as LeadScoringRule::field).
            $table->string('type');

            // Ordered list of allowed strings for a `select` field only;
            // null for every other type.
            $table->json('options')->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Same field name may exist in different tenants; within one
            // tenant, the machine key must be unique.
            $table->unique(['tenant_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_custom_field_definitions');
    }
};
