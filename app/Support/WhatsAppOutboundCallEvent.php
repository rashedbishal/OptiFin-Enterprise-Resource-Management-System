<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * WhatsApp Outbound Calling — one `calls[]` item of a webhook whose
 * `direction` is `BUSINESS_INITIATED` (the verified contract's own signal
 * that this is our outbound call, never a `USER_INITIATED` inbound one —
 * WhatsAppCallEvent::fromWebhook() already rejects BUSINESS_INITIATED
 * outright, so the caller must route on `direction` BEFORE ever reaching
 * that parser, not after).
 *
 * A `connect` item here carries Meta's SDP ANSWER (`session.sdp_type ===
 * 'answer'`), the mirror image of WhatsAppCallEvent's inbound `connect`,
 * which always carries an offer — this is exactly why they are two
 * different parsers rather than one branching on direction internally.
 *
 * fromWebhook() returns either the event or a short, log-safe reason string,
 * never an exception — same discipline as WhatsAppCallEvent.
 */
final class WhatsAppOutboundCallEvent
{
    public const string EVENT_CONNECT = 'connect';

    public const string EVENT_TERMINATE = 'terminate';

    public const int MAX_CALL_ID_LENGTH = 191;

    public const int MAX_SDP_BYTES = 16384;

    public const string REASON_MALFORMED = 'malformed_outbound_call_item';

    public const string REASON_MISSING_ID = 'missing_call_id';

    public const string REASON_UNSUPPORTED_EVENT = 'unsupported_event';

    public const string REASON_INVALID_TIMESTAMP = 'invalid_timestamp';

    public const string REASON_MISSING_ANSWER = 'missing_answer';

    public const string REASON_OVERSIZED_SDP = 'oversized_sdp';

    public const string REASON_INVALID_SDP = 'invalid_sdp';

    private function __construct(
        public readonly string $callId,
        public readonly string $event,
        public readonly int $timestamp,
        public readonly ?string $sdp,
        public readonly ?string $statusToken,
        public readonly bool $hasErrors,
        public readonly ?string $errorToken,
        public readonly ?int $startTime,
        public readonly ?int $endTime,
        public readonly ?int $duration,
    ) {}

    /**
     * @param  array<mixed>  $change  the webhook `changes[]` item that holds this call
     */
    public static function fromWebhook(array $change, mixed $call): self|string
    {
        $value = $change['value'] ?? null;

        if (! is_array($call) || ! is_array($value)) {
            return self::REASON_MALFORMED;
        }

        $callId = $call['id'] ?? null;

        if (! is_string($callId) || $callId === '' || strlen($callId) > self::MAX_CALL_ID_LENGTH) {
            return self::REASON_MISSING_ID;
        }

        $event = $call['event'] ?? null;

        if ($event !== self::EVENT_CONNECT && $event !== self::EVENT_TERMINATE) {
            return self::REASON_UNSUPPORTED_EVENT;
        }

        $timestamp = self::parseTimestamp($call['timestamp'] ?? null);

        if ($timestamp === null) {
            return self::REASON_INVALID_TIMESTAMP;
        }

        $sdp = null;

        if ($event === self::EVENT_CONNECT) {
            $sdp = self::validateAnswer($call['session'] ?? null);

            if ($sdp === self::REASON_MISSING_ANSWER || $sdp === self::REASON_OVERSIZED_SDP || $sdp === self::REASON_INVALID_SDP) {
                return $sdp;
            }
        }

        $errors = $value['errors'] ?? $call['errors'] ?? null;
        $hasErrors = is_array($errors) && $errors !== [];

        return new self(
            callId: $callId,
            event: $event,
            timestamp: $timestamp,
            sdp: $sdp,
            statusToken: self::normalizeStatus($call['status'] ?? null),
            hasErrors: $hasErrors,
            errorToken: $hasErrors ? self::errorToken($errors) : null,
            startTime: self::positiveInt($call['start_time'] ?? null),
            endTime: self::positiveInt($call['end_time'] ?? null),
            duration: self::positiveInt($call['duration'] ?? null),
        );
    }

    public static function loggableId(mixed $call): ?string
    {
        $id = is_array($call) ? ($call['id'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $safe = preg_replace('/[^\x21-\x7E]/', '', $id);

        return is_string($safe) && $safe !== '' ? substr($safe, 0, self::MAX_CALL_ID_LENGTH) : null;
    }

    public function isConnect(): bool
    {
        return $this->event === self::EVENT_CONNECT;
    }

    public function isTerminate(): bool
    {
        return $this->event === self::EVENT_TERMINATE;
    }

    public function pickedUp(): bool
    {
        return $this->startTime !== null;
    }

    public function rawMetaStatus(): ?string
    {
        return $this->errorToken ?? $this->statusToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['callId' => $this->callId, 'event' => $this->event, 'timestamp' => $this->timestamp];
    }

    /**
     * @return string the SDP answer, or one of the REASON_* constants
     */
    private static function validateAnswer(mixed $session): string
    {
        if (! is_array($session) || ($session['sdp_type'] ?? null) !== 'answer') {
            return self::REASON_MISSING_ANSWER;
        }

        $sdp = $session['sdp'] ?? null;

        if (! is_string($sdp) || trim($sdp) === '') {
            return self::REASON_MISSING_ANSWER;
        }

        if (strlen($sdp) > self::MAX_SDP_BYTES) {
            return self::REASON_OVERSIZED_SDP;
        }

        if (! str_starts_with(ltrim($sdp), 'v=0')) {
            return self::REASON_INVALID_SDP;
        }

        return self::withFinalTerminator($sdp);
    }

    /**
     * Same TrimStrings-middleware CRLF restoration as WhatsAppCallEvent's
     * offer parsing — see that class's docblock for the full reasoning.
     * Duplicated rather than shared: that method is private to a class this
     * one deliberately never depends on, so inbound offer parsing stays
     * completely untouched by anything here.
     */
    private static function withFinalTerminator(string $sdp): string
    {
        if (str_ends_with($sdp, "\n")) {
            return $sdp;
        }

        return $sdp.(str_ends_with($sdp, "\r") ? "\n" : "\r\n");
    }

    private static function parseTimestamp(mixed $timestamp): ?int
    {
        if ($timestamp === null) {
            return Carbon::now()->getTimestamp();
        }

        if (is_int($timestamp)) {
            return $timestamp > 0 ? $timestamp : null;
        }

        return is_string($timestamp) && ctype_digit($timestamp) && (int) $timestamp > 0 ? (int) $timestamp : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function normalizeStatus(mixed $status): ?string
    {
        $parts = is_array($status) ? array_values(array_filter($status, 'is_string')) : (is_string($status) ? [$status] : []);
        $token = strtoupper(implode('|', $parts));
        $token = preg_replace('/[^A-Z_|]/', '', $token);

        return is_string($token) && $token !== '' ? substr($token, 0, 64) : null;
    }

    private static function errorToken(mixed $errors): string
    {
        $first = is_array($errors) ? reset($errors) : null;
        $code = is_array($first) ? ($first['code'] ?? null) : null;
        $safe = is_scalar($code) ? preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $code) : null;

        return 'ERROR:'.(is_string($safe) && $safe !== '' ? substr($safe, 0, 40) : 'UNKNOWN');
    }
}
