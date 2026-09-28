<?php

namespace App\Support;

/**
 * WhatsApp Outbound Calling — one `statuses[]` item of a webhook whose
 * `type` is `call`. Meta reuses the SAME `statuses[]` array key that
 * WhatsApp message delivery/read receipts already use (see
 * LeadWebhookController::whatsapp(), which checks `type` before routing an
 * item here at all — a message-status item never reaches this parser, and
 * this parser never reaches message-status handling).
 *
 * This is Meta's mid-call status lifecycle for a business-initiated call:
 * RINGING (the consumer's device is now audibly ringing) -> ACCEPTED (the
 * consumer answered) or REJECTED (the consumer declined) -> a `terminate`
 * webhook always follows, for any outcome.
 *
 * `ACCEPTED` is the one authoritative "the consumer actually answered"
 * signal this app previously had no source for — never inferred from the
 * earlier `connect` webhook's SDP answer (which fires before the consumer's
 * device even rings — see RecordOutboundCallEvent) and never from
 * `start_time` (only present, after the fact, on `terminate`).
 *
 * fromWebhook() returns either the event or a short, log-safe reason string,
 * never an exception — same discipline as WhatsAppCallEvent /
 * WhatsAppOutboundCallEvent.
 */
final class WhatsAppCallStatusEvent
{
    public const string TYPE_CALL = 'call';

    public const string STATUS_RINGING = 'RINGING';

    public const string STATUS_ACCEPTED = 'ACCEPTED';

    public const string STATUS_REJECTED = 'REJECTED';

    public const int MAX_CALL_ID_LENGTH = 191;

    public const string REASON_NOT_A_CALL_STATUS = 'not_a_call_status';

    public const string REASON_MALFORMED = 'malformed_call_status_item';

    public const string REASON_MISSING_ID = 'missing_call_id';

    public const string REASON_UNSUPPORTED_STATUS = 'unsupported_call_status';

    private function __construct(
        public readonly string $callId,
        public readonly string $status,
    ) {}

    /**
     * @param  mixed  $status  one `statuses[]` item
     */
    public static function fromWebhook(mixed $status): self|string
    {
        if (! is_array($status)) {
            return self::REASON_MALFORMED;
        }

        if (($status['type'] ?? null) !== self::TYPE_CALL) {
            return self::REASON_NOT_A_CALL_STATUS;
        }

        $callId = $status['id'] ?? null;

        if (! is_string($callId) || $callId === '' || strlen($callId) > self::MAX_CALL_ID_LENGTH) {
            return self::REASON_MISSING_ID;
        }

        $raw = $status['status'] ?? null;
        $normalized = is_string($raw) ? strtoupper(trim($raw)) : null;

        if (! in_array($normalized, [self::STATUS_RINGING, self::STATUS_ACCEPTED, self::STATUS_REJECTED], true)) {
            return self::REASON_UNSUPPORTED_STATUS;
        }

        return new self(callId: $callId, status: $normalized);
    }

    public function isRinging(): bool
    {
        return $this->status === self::STATUS_RINGING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['callId' => $this->callId, 'status' => $this->status];
    }
}
