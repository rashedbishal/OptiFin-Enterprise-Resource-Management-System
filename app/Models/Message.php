<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Feature #15 — one inbound or outbound message within a Conversation.
 * Inbound rows are an ADDITIVE dual-write alongside Lead.source_data's
 * existing raw-payload storage (see LeadWebhookController) — this table
 * never replaces that write, only supplements it going forward; no
 * historical inbound data is backfilled into this table. `status`/
 * `status_updated_at`/`failure_reason` are meaningful only for
 * direction=Outbound — see MessageStatus's own docblock.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $lead_id
 * @property MessageChannel $channel
 * @property MessageDirection $direction
 * @property MessageType $message_type
 * @property string|null $body
 * @property string|null $external_message_id
 * @property string|null $external_participant_id
 * @property MessageStatus|null $status
 * @property Carbon|null $status_updated_at
 * @property string|null $failure_reason
 * @property string|null $resend_message_id
 * @property string|null $sent_by
 * @property array<string, mixed>|null $metadata
 * @property-read Conversation $conversation
 * @property-read Lead $lead
 * @property-read TenantUser|null $sentBy
 * @property-read Message|null $resend
 */
#[Fillable([
    'tenant_id', 'conversation_id', 'lead_id', 'channel', 'direction', 'message_type', 'body',
    'external_message_id', 'external_participant_id', 'status', 'status_updated_at',
    'failure_reason', 'sent_by', 'metadata',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'direction' => MessageDirection::class,
            'message_type' => MessageType::class,
            'status' => MessageStatus::class,
            'status_updated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<TenantUser, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'sent_by');
    }

    /**
     * Set on a Failed message the moment Show::resendMessage() creates a
     * resend for it — null means "not yet resent." Points forward to the
     * new resend row, the same shape as Lead::mergedInto()/
     * merged_into_lead_id. `resend_message_id` is deliberately left out of
     * #[Fillable] above, same trap/pattern as `merged_into_lead_id`: it
     * must only ever be set by resendMessage()'s own raw update, never
     * from a create()/update() payload.
     *
     * @return BelongsTo<Message, $this>
     */
    public function resend(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resend_message_id');
    }
}
