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
        Schema::create('lead_tags', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Tenant self-service, like LeadScoringRule — not a
            // Platform-Admin-managed shared catalog like LeadStatus/Currency.
            // A tag is a tenant's own business-specific label ("VIP", "Needs
            // Follow-up"), with no cross-tenant consistency need, so the same
            // name/slug can exist independently in different tenants. Managed
            // by a Client Admin via the existing "update" module permission
            // (see App\Policies\LeadTagPolicy) — no new PermissionSlug.
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');

            // Derived from name via Str::slug() — enforces the uniqueness
            // constraint below case/formatting-insensitively (e.g. "VIP" and
            // "vip" both slugify to "vip"), same technique as
            // LeadStatus::saveStatus().
            $table->string('slug');

            // Reuses LeadStatus::availableColors()'s palette (see
            // LeadTag::availableColors()) for visual consistency with the
            // rest of the module — plain flux:badge :color prop, no custom
            // Tailwind class mapping needed like LeadStatus's card/pulse/
            // shimmer classes.
            $table->string('color');

            $table->timestamps();

            // Same tag name may exist in different tenants; within one
            // tenant, the slug must be unique.
            $table->unique(['tenant_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_tags');
    }
};
