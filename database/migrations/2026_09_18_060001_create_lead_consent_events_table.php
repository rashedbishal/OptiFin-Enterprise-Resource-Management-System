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
        Schema::create('lead_consent_events', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->enum('consent_type', array_column(ConsentType::cases(), 'value'));
            $table->enum('status', array_column(ConsentStatus::cases(), 'value'));
            $table->enum('source', array_column(ConsentSource::cases(), 'value'));

            $table->ulid('website_form_id')->nullable();
            $table->string('website_form_name')->nullable();
            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            $table->string('policy_version')->nullable();
            $table->string('policy_url')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('occurred_at');

            // Plain timestamps(), same as every other log table in this
            // module (LeadStatusLog, DealStatusLog, TaskStatusLog) — the
            // append-only guarantee here is a code-discipline convention (no
            // call site in this app ever calls update()/delete() against
            // this model), not a schema-level restriction, exactly like
            // those tables' own "immutable in practice" treatment.
            $table->timestamps();

            // Deliberately NO unique constraint — every grant/withdraw
            // creates one more row, by design (this is the permanent,
            // append-only history; lead_consents holds the current state).
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_consent_events');
    }
};
