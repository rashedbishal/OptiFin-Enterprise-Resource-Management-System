<?php

use App\Enums\MessageChannel;
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
        Schema::create('conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Denormalized, same discipline as every other lead-child table
            // in this module — every query filters tenant_id explicitly.
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('lead_id')->constrained('leads')->cascadeOnDelete();

            // Which specific account/number/page this thread belongs to —
            // stored explicitly (not assumed from tenant+channel alone),
            // since a tenant could in principle configure more than one
            // source of the same channel type in the future.
            $table->foreignUlid('lead_source_id')->constrained('lead_sources')->restrictOnDelete();

            $table->enum('channel', array_column(MessageChannel::cases(), 'value'));

            // WhatsApp: the customer's phone number. Messenger: the PSID.
            // Instagram: the IGSID. Opaque on purpose — the three channels
            // do not share an identifier shape (see the Architecture Brief's
            // Conversation Identity section), so this column is never typed
            // or parsed as anything more specific than "this channel's own
            // thread identifier."
            $table->string('external_thread_id');

            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();

            $table->timestamps();

            // The exact identity Meta itself already uses to route a webhook
            // payload to "this specific ongoing thread" — safe to make
            // unique because external_thread_id is always scoped to the one
            // specific lead_source_id it's paired with here.
            $table->unique(['tenant_id', 'lead_source_id', 'channel', 'external_thread_id'], 'conversations_identity_unique');

            $table->index(['tenant_id', 'lead_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
