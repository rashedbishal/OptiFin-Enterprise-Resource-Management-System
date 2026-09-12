<?php

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
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
        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained('conversations')->cascadeOnDelete();

            // Denormalized from conversation_id — both "this lead's whole
            // message history" and "this tenant's whole message volume" are
            // realistic queries that would otherwise need a join, the same
            // reasoning LeadConsentEvent/LeadCustomFieldValue already apply.
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->enum('channel', array_column(MessageChannel::cases(), 'value'));

            $table->enum('direction', array_column(MessageDirection::cases(), 'value'));
            $table->enum('message_type', array_column(MessageType::cases(), 'value'))->default(MessageType::Text->value);
            $table->text('body')->nullable();

            // The platform's own message id (wamid / mid) — nullable because
            // an outbound row is created with status=pending BEFORE the
            // synchronous send call returns one, and an inbound dual-write
            // row may not always carry one either depending on payload
            // shape. Unique per (tenant_id, channel) when present — NULL
            // never collides under standard SQL unique-index semantics, the
            // same trick this module already relies on elsewhere (Feature
            // #13's website_form_fields constraints).
            $table->string('external_message_id')->nullable();

            // The OTHER party's platform id relative to `direction` — for an
            // inbound message this is the sender; for outbound, the
            // recipient. Our own side's identity is always the
            // conversation's own lead_source_id, so it is never repeated
            // here.
            $table->string('external_participant_id')->nullable();

            // Meaningful only for direction=outbound — see MessageStatus's
            // own docblock for why inbound rows always leave this null.
            $table->enum('status', array_column(MessageStatus::cases(), 'value'))->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->string('failure_reason')->nullable();

            // Which TenantUser sent this — null for an inbound message and
            // null-on-delete so removing a staff account never deletes
            // message history, same convention as LeadNote/LeadConsent's
            // own actor columns.
            $table->foreignUlid('sent_by')->nullable()->constrained('tenant_users')->nullOnDelete();

            // The raw webhook/API-response payload snapshot — mirrors
            // Lead.source_data's existing role, for debugging/audit without
            // inventing a new column per platform quirk.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'external_message_id'], 'messages_external_id_unique');
            $table->index(['conversation_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
