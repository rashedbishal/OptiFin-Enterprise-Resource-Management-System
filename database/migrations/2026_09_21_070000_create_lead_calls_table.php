<?php

use App\Enums\CallStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WhatsApp inbound calling, Build Step 1 — one row per Meta call id.
     * Plain string columns for every enum-backed value (same shape as
     * tasks/deals): no DB CHECK constraint, so a later case addition never
     * needs a constraint-rewriting migration.
     */
    public function up(): void
    {
        Schema::create('lead_calls', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Cascade like every other lead child (deals/tasks/messages): a
            // deleted lead takes its call history with it — erasing a person
            // must not leave their calls behind. A deletion is refused while
            // a call is still active (LeadDeletionEligibilityService).
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();

            // restrict, same as conversations: a source with call history
            // can't be silently deleted out from under it.
            $table->foreignUlid('lead_source_id')->constrained('lead_sources')->restrictOnDelete();

            // Nulled, not cascaded, when the conversation is deleted on its
            // own (DeleteLeadConversation) — the call record outlives that.
            $table->foreignUlid('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();

            // Meta's `wacid.…` call id — the idempotency key for every
            // webhook/agent operation on a call.
            $table->string('external_call_id', 191);

            $table->string('status', 16)->default(CallStatus::Ringing->value);
            $table->string('end_reason', 64)->nullable();

            // Last Meta step that succeeded (pre_accepted|accepted) — see
            // App\Enums\CallMetaStage.
            $table->string('meta_stage', 32)->nullable();

            // Raw Meta-reported terminate status/error token, stored
            // verbatim and never branched on (its exact shape is still
            // unverified). meta_terminated_at marks that the terminate
            // webhook was applied — the idempotency guard against a
            // duplicate delivery.
            $table->string('meta_status', 64)->nullable();
            $table->timestamp('meta_terminated_at')->nullable();

            // Routing snapshot, frozen at arrival — see App\Enums\
            // CallRoutingMode. routed_user_ids is the full list of users
            // rung; it is also the authorization list for fetching the SDP
            // offer / answering / rejecting.
            $table->foreignUlid('assignee_id_at_arrival')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->string('routing_mode', 32);
            $table->json('routed_user_ids');

            $table->foreignUlid('claimed_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // Null for a row that never rang (no eligible recipient, or a
            // terminate-before-connect arrival).
            $table->timestamp('ringing_expires_at')->nullable();

            $table->timestamps();

            $table->unique(['lead_source_id', 'external_call_id'], 'lead_calls_source_call_unique');
            $table->index(['tenant_id', 'lead_id', 'created_at']);
            $table->index(['status', 'ringing_expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_calls');
    }
};
