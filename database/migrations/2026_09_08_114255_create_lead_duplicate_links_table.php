<?php

use App\Enums\LeadDuplicateLinkStatus;
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
        Schema::create('lead_duplicate_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // One row per detected pair, ever — App\Services\LeadDuplicateDetectionService
            // always stores lead_id as the lexicographically smaller ulid of
            // the pair (App\Models\LeadDuplicateLink::pairKeys()), so the
            // same two leads can never produce two rows regardless of which
            // one triggered detection first. This is also what lets a
            // "dismissed" decision stick permanently instead of the pair
            // being re-flagged on every future detection pass.
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUlid('duplicate_lead_id')->constrained('leads')->cascadeOnDelete();

            $table->enum('status', array_column(LeadDuplicateLinkStatus::cases(), 'value'))
                ->default(LeadDuplicateLinkStatus::Pending->value);

            // Which field(s) triggered the match — ['email'], ['phone'], or
            // both. Display-only (why this pair was flagged), never read by
            // matching logic itself.
            $table->json('matched_fields')->nullable();

            $table->timestamp('detected_at')->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            // Populated only when status becomes 'merged': for every field
            // App\Services\LeadMergeService found conflicting, which side was
            // chosen and both leads' values at merge time — a permanent audit
            // record independent of the leads' own values, which may keep
            // changing afterward. See App\Services\LeadMergeService::merge().
            $table->json('field_resolutions')->nullable();

            $table->timestamps();

            $table->unique(['lead_id', 'duplicate_lead_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_duplicate_links');
    }
};
