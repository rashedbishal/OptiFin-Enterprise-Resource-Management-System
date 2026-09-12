<?php

namespace App\Actions\Tenant;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Feature #15 — the single additive dual-write primitive every inbound
 * webhook handler (Messenger/Instagram/WhatsApp) calls alongside its
 * existing, UNCHANGED Lead.source_data JSON append. Finds-or-creates the
 * Conversation this message belongs to, updates last_inbound_at, and
 * appends one normalized Message row — never touches, removes, or
 * duplicates the legacy JSON write, which stays the single source of truth
 * for every pre-existing consumer (Lead::messageTimeline(), the Facebook
 * Lead Ads spreadsheet, every existing webhook test).
 */
class RecordInboundMessage
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function handle(
        Lead $lead,
        LeadSource $source,
        MessageChannel $channel,
        string $externalThreadId,
        ?string $body,
        ?string $externalMessageId = null,
        ?array $metadata = null,
    ): Message {
        return DB::transaction(function () use ($lead, $source, $channel, $externalThreadId, $body, $externalMessageId, $metadata): Message {
            $conversation = Conversation::query()->firstOrCreate(
                [
                    'tenant_id' => $lead->tenant_id,
                    'lead_source_id' => $source->id,
                    'channel' => $channel,
                    'external_thread_id' => $externalThreadId,
                ],
                ['lead_id' => $lead->id],
            );

            // A conversation created before this specific message (e.g. by
            // an earlier message in the same request) already has the right
            // lead_id; a lead-merge could in principle change which lead a
            // conversation should now point at — not handled here (see the
            // Architecture Brief's own scope boundary — conversation/message
            // reparenting on merge is not part of Phase 1).
            $conversation->update(['last_inbound_at' => now()]);

            return Message::create([
                'tenant_id' => $lead->tenant_id,
                'conversation_id' => $conversation->id,
                'lead_id' => $lead->id,
                'channel' => $channel,
                'direction' => MessageDirection::Inbound,
                'message_type' => MessageType::Text,
                'body' => $body,
                'external_message_id' => $externalMessageId,
                'external_participant_id' => $externalThreadId,
                'metadata' => $metadata,
            ]);
        });
    }
}
