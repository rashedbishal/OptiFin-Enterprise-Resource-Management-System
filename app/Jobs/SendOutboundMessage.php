<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Events\LeadUpdated;
use App\Models\Message;
use App\Services\MessageSenderFactory;
use App\Services\OutboundEligibilityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Feature #15 — sends one already-created, status=pending Message. The row
 * is always created SYNCHRONOUSLY before this job is ever dispatched (see
 * Leads\Show::sendMessage()) — this job only ever UPDATES that one existing
 * row, it never creates a Message, which is what makes a job retry safe:
 * retrying re-runs handle() against the same row, never inserts a second
 * one.
 *
 * Retry policy (approved architecture): a transient infrastructure/network
 * failure (an uncaught exception — Laravel's queue retries automatically up
 * to $tries) may be retried; a CONFIRMED platform rejection (a SendResult::
 * failure() — the *Sender already got a response back from Meta saying no)
 * is terminal and marks the message failed without any further attempt.
 * Manual resend is a brand-new job dispatch against a *new* pending
 * Message row created by the user's own explicit action, never automatic.
 *
 * Known, accepted residual risk (not solved by this design, or by Meta's
 * own APIs): a response-read timeout occurring AFTER the platform already
 * accepted the message is indistinguishable, at the HTTP-client level, from
 * a timeout that occurred before the platform ever received it. A retry in
 * that specific narrow window could in principle cause a real duplicate
 * platform-side message. This is a genuine limitation of synchronous
 * HTTP + retry with no server-provided idempotency key on Meta's send
 * endpoints — mitigated only by keeping $tries low, not eliminated.
 */
class SendOutboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30];

    public function __construct(public Message $message) {}

    public function handle(OutboundEligibilityService $eligibility, MessageSenderFactory $senderFactory): void
    {
        if (! Message::whereKey($this->message->getKey())->exists()) {
            // The conversation was deleted (App\Actions\Tenant\
            // DeleteLeadConversation) while this job was queued — nothing
            // left to send or to update.
            return;
        }

        $this->message->refresh();

        if ($this->message->status !== MessageStatus::Pending) {
            // Already handled by an earlier attempt or a manual action —
            // never re-send an already-resolved message.
            return;
        }

        $conversation = $this->message->conversation;
        $eligibilityResult = $eligibility->evaluate($conversation);

        if (! $eligibilityResult->eligible) {
            $this->markFailed($eligibilityResult->reason);

            return;
        }

        $sender = $senderFactory->make($this->message->channel);
        $sendResult = $this->message->hasMedia()
            ? $sender->sendMedia($conversation, $this->message)
            : $sender->send($conversation, (string) $this->message->body);

        if (! $sendResult->successful) {
            $this->markFailed($sendResult->failureReason);

            return;
        }

        $this->message->update([
            'external_message_id' => $sendResult->externalMessageId,
            'status' => MessageStatus::Sent,
            'status_updated_at' => now(),
            'metadata' => $sendResult->rawResponse,
        ]);

        $conversation->update(['last_outbound_at' => now()]);

        event(new LeadUpdated($this->message->lead));
    }

    /**
     * Called once every retry attempt is exhausted after an uncaught
     * (transient) exception — the message must never be left stuck in
     * `pending` forever just because every retry hit a network failure.
     */
    public function failed(?Throwable $exception): void
    {
        if (! Message::whereKey($this->message->getKey())->exists()) {
            return;
        }

        $this->message->refresh();

        if ($this->message->status === MessageStatus::Pending) {
            $this->markFailed('Could not be sent due to a temporary system error. Please try again.');
        }
    }

    private function markFailed(?string $reason): void
    {
        $this->message->update([
            'status' => MessageStatus::Failed,
            'status_updated_at' => now(),
            'failure_reason' => $reason,
        ]);

        event(new LeadUpdated($this->message->lead));
    }
}
