<?php

use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
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
        Schema::create('lead_consents', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Denormalized, same discipline as every other lead-child table in
            // this module — every query filters tenant_id explicitly rather
            // than relying on BelongsToTenant's global scope, which doesn't
            // activate in webhook/job/command contexts.
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->enum('consent_type', array_column(ConsentType::cases(), 'value'));
            $table->enum('status', array_column(ConsentStatus::cases(), 'value'));
            $table->enum('source', array_column(ConsentSource::cases(), 'value'));

            // A point-in-time snapshot, never an FK to website_forms — same
            // reasoning as Lead.source_data's own website_form_id/_name
            // snapshot (Feature #13): deleting a WebsiteForm must never touch
            // a Lead's consent record.
            $table->ulid('website_form_id')->nullable();
            $table->string('website_form_name')->nullable();

            // Null for a public/system-originated Website Form submission,
            // set for a manual admin action — same convention as
            // LeadDetailUpdateLog.changed_by/DealStatusLog.changed_by.
            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            $table->string('policy_version')->nullable();
            $table->string('policy_url')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            // This row IS the current effective state for this lead's given
            // consent type — one row per (lead_id, consent_type), upserted on
            // every grant/withdraw. "Never provided" is represented by the
            // absence of a row, not a third status value.
            $table->unique(['lead_id', 'consent_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_consents');
    }
};
