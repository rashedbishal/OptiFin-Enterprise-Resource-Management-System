<?php

namespace App\Jobs;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\LeadUpdated;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Feature #15 — dispatched from the (unchanged, always-200) webhook
 * handlers whenever a delivery/read/failed status event arrives, rather
 * than updating the Message row inline in the webhook request — this
 * preserves the existing "always ACK 200" policy (Meta disables a webhook
 * subscription after enough non-200 responses) while still guaranteeing a
 * legitimate status event is never silently dropped: if the target Message
 * row can't be found yet (a narrow race — see the Final Architecture
 * Validation Report §D — the row is always created before any send is
 * attempted, but a status event could in principle arrive before this job's
 * own write of `external_message_id` commits), the job's own queue retry
 * (a few attempts, short backoff) gives it another chance; only after every
 * attempt is exhausted does it log an operator-visible warning — it is
 * never silently discarded.
 *
 * Correlation strategy is channel-specific (Final Architecture Validation
 * Report §2), never one shared "look up by external_message_id" path:
 *  - WhatsApp: reliably per-message via `external_message_id` (wamid).
 *  - Messenger/Instagram: PRIMARILY by conversation + watermark (a
 *    timestamp cutoff — "everything up to this time is delivered/read"),
 *    since the per-message `mids` array Meta's delivery event carries is
 *    documented as sometimes absent; `mids` is used opportunistically for a
 *    tighter single-message update when present.
 *
 * Status updates are strictly monotonic — `applyStatus()` never downgrades
 * an already-more-advanced status (see MessageStatus::rank()), and never
 * touches an inbound row (which has no status at all) or a row that
 * doesn't have a status yet (still literally `pending`, meaning "sent"
 * hasn't even been recorded — a delivery/read event for it would be
 * platform-impossible, but the null-check guards it regardless).
 */
class ReconcileOutboundMessageStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 10, 20];

    /**
     * @param  array<int, string>  $externalMessageIds  opportunistic per-message ids (Messenger's `mids`), empty when unavailable
     */
    public function __construct(
        public string $tenantId,
        public MessageChannel $channel,
        public MessageStatus $status,
        public ?string $externalMessageId = null,
        public array $externalMessageIds = [],
        public ?string $leadSourceId = null,
        public ?string $conversationExternalThreadId = null,
        public ?int $watermarkTimestamp = null,
        public ?string $failureReason = null,
    ) {}

    public function handle(): void
    {
        // WhatsApp path — reliable per-message id.
        if ($this->externalMessageId !== null) {
            $message = Message::where('tenant_id', $this->tenantId)
                ->where('channel', $this->channel)
                ->where('external_message_id', $this->externalMessageId)
                ->first();

            if ($message === null) {
                // Let the job retry — see class docblock for why this must
                // never be treated as "permanently gone" on the first miss.
                throw new RuntimeException("No message found for external_message_id [{$this->externalMessageId}] on attempt {$this->attempts()}.");
            }

            $this->applyStatus($message->id, $this->failureReason);

            return;
        }

        // Messenger/Instagram opportunistic mids — tighter than watermark
        // when present, applied per message.
        foreach ($this->externalMessageIds as $mid) {
            $message = Message::where('tenant_id', $this->tenantId)
                ->where('channel', $this->channel)
                ->where('external_message_id', $mid)
                ->first();

            if ($message !== null) {
                $this->applyStatus($message->id, $this->failureReason);
            }
        }

        // Messenger/Instagram watermark — the primary, reliable mechanism:
        // every outbound message in this conversation up to the watermark
        // timestamp is at least at this status. The Conversation itself is
        // always created before any send is attempted, so a missing
        // Conversation here means there is genuinely nothing to reconcile
        // (not a race worth retrying).
        if ($this->watermarkTimestamp !== null && $this->leadSourceId !== null && $this->conversationExternalThreadId !== null) {
            $conversation = Conversation::where('tenant_id', $this->tenantId)
                ->where('lead_source_id', $this->leadSourceId)
                ->where('channel', $this->channel)
                ->where('external_thread_id', $this->conversationExternalThreadId)
                ->first();

            if ($conversation === null) {
                return;
            }

            $watermarkAt = Carbon::createFromTimestamp($this->watermarkTimestamp);

            Message::where('conversation_id', $conversation->id)
                ->where('direction', MessageDirection::Outbound)
                ->where('created_at', '<=', $watermarkAt)
                ->pluck('id')
                ->each(fn (string $messageId) => $this->applyStatus($messageId, $this->failureReason));
        }
    }

    /**
     * Logged once every retry attempt for a WhatsApp per-message lookup is
     * exhausted — the event is never silently dropped, it becomes an
     * operator-visible warning instead.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Feature #15: outbound message status reconciliation could not find a matching message after all retries.', [
            'tenant_id' => $this->tenantId,
            'channel' => $this->channel->value,
            'status' => $this->status->value,
            'external_message_id' => $this->externalMessageId,
        ]);
    }

    /**
     * Re-fetches and locks the row inside a transaction rather than
     * trusting the already-loaded $message passed in by handle() — two
     * status webhooks for the same message arriving close enough together
     * (e.g. "delivered" immediately followed by "read") could otherwise
     * run as two genuinely concurrent job executions that both read the
     * SAME pre-update status, both independently conclude their own
     * target status is an advance, and then write in an order that
     * downgrades the row (found during Feature #15's Final Verification
     * audit — a real race under concurrent queue workers, not merely a
     * single-worker sequential-processing assumption). Locking here makes
     * the read-check-write atomic per message, closing that race.
     */
    private function applyStatus(string $messageId, ?string $failureReason): void
    {
        DB::transaction(function () use ($messageId, $failureReason): void {
            $message = Message::whereKey($messageId)->lockForUpdate()->first();

            if ($message === null || $message->status === null || $this->status->rank() <= $message->status->rank()) {
                return;
            }

            $message->update([
                'status' => $this->status,
                'status_updated_at' => now(),
                'failure_reason' => $this->status === MessageStatus::Failed ? $failureReason : $message->failure_reason,
            ]);

            event(new LeadUpdated($message->lead));
        });
    }
}
