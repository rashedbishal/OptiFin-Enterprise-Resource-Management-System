<?php

namespace App\Models;

use App\Enums\MessageChannel;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Feature #15 — one conversational thread with a Lead over one channel,
 * identified by the pairing of this tenant's own account (`lead_source_id`)
 * and the platform's own thread identifier for the other party
 * (`external_thread_id` — the customer's phone number for WhatsApp, PSID for
 * Messenger, IGSID for Instagram). No BelongsToTenant trait — every query
 * filters tenant_id explicitly, same discipline as every other tenant-owned
 * row in this module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_id
 * @property string $lead_source_id
 * @property MessageChannel $channel
 * @property string $external_thread_id
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_outbound_at
 * @property-read Lead $lead
 * @property-read LeadSource $leadSource
 */
#[Fillable(['tenant_id', 'lead_id', 'lead_source_id', 'channel', 'external_thread_id', 'last_inbound_at', 'last_outbound_at'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Whether this channel's standard messaging window is still open,
     * derived purely from our own last-recorded inbound activity — no
     * platform exposes a "check my window" API, so this is the only source
     * of truth available (see the Architecture Brief's Messaging Eligibility
     * section).
     */
    public function isWithinMessagingWindow(): bool
    {
        if ($this->last_inbound_at === null) {
            return false;
        }

        return $this->last_inbound_at->addHours($this->channel->windowHours())->isFuture();
    }
}
