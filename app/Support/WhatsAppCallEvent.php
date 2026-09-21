<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * WhatsApp inbound calling (Step 2) — one `calls[]` item of a WhatsApp Cloud
 * API webhook, validated and normalized. Pure: no database, cache or logging.
 *
 * fromWebhook() returns either the event or a short, log-safe reason string
 * saying why the item is ignored — never an exception, so one malformed item
 * can't affect the rest of a payload. The SDP offer lives only in this object
 * (and, later, in the encrypted cache); __debugInfo() hides it and the caller
 * number so an accidental dump or log of the object can't leak either.
 *
 * Contract notes (Meta's user-initiated-calls documentation):
 *  - `from` is the caller and `to` the callee (the business). `from` may be
 *    omitted for a username-only user, so `contacts[].wa_id` is the fallback.
 *    Meta's older `connect` example has `from` equal to the business's own
 *    display number, so a candidate equal to the business number is never
 *    accepted as the caller.
 *  - `direction` is not guaranteed on `connect`; it is only rejected when
 *    present and not USER_INITIATED.
 *  - The SDP offer arrives with its final CRLF stripped by Laravel's
 *    TrimStrings middleware; the parser restores that single terminator (see
 *    withFinalTerminator()) so the cached offer is the SDP Meta sent.
 *  - On `terminate`, `start_time`/`end_time`/`duration` appear only when the
 *    call was picked up, and `errors` (a sibling of `calls` under `value`)
 *    appears only for failed calls. The `status` value's exact shape is not
 *    documented, so it is normalized to an uppercase token and stored raw.
 */
final class WhatsAppCallEvent
{
    public const string EVENT_CONNECT = 'connect';

    public const string EVENT_TERMINATE = 'terminate';

    public const int MAX_CALL_ID_LENGTH = 191;

    public const int MAX_SDP_BYTES = 16384;

    public const string REASON_MALFORMED = 'malformed_call_item';

    public const string REASON_MISSING_ID = 'missing_call_id';

    public const string REASON_INVALID_ID = 'invalid_call_id';

    public const string REASON_UNSUPPORTED_EVENT = 'unsupported_event';

    public const string REASON_UNSUPPORTED_DIRECTION = 'unsupported_direction';

    public const string REASON_INVALID_TIMESTAMP = 'invalid_timestamp';

    public const string REASON_MISSING_OFFER = 'missing_offer';

    public const string REASON_OVERSIZED_SDP = 'oversized_sdp';

    public const string REASON_INVALID_SDP = 'invalid_sdp';

    public const string REASON_UNUSABLE_CALLER = 'unusable_caller';

    private function __construct(
        public readonly string $callId,
        public readonly string $event,
        public readonly ?string $callerPhone,
        public readonly ?string $callerName,
        public readonly ?string $phoneNumberId,
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
     * @param  array<mixed>  $change  the webhook `changes[]` item that holds this call (its `value` carries metadata/contacts/errors)
     */
    public static function fromWebhook(array $change, mixed $call): self|string
    {
        $value = $change['value'] ?? null;

        if (! is_array($call) || ! is_array($value)) {
            return self::REASON_MALFORMED;
        }

        $callId = $call['id'] ?? null;

        if (! is_string($callId) || $callId === '') {
            return self::REASON_MISSING_ID;
        }

        if (strlen($callId) > self::MAX_CALL_ID_LENGTH || preg_match('/\s/', $callId) === 1) {
            return self::REASON_INVALID_ID;
        }

        $event = $call['event'] ?? null;

        if ($event !== self::EVENT_CONNECT && $event !== self::EVENT_TERMINATE) {
            return self::REASON_UNSUPPORTED_EVENT;
        }

        $direction = $call['direction'] ?? null;

        if ($direction !== null && $direction !== 'USER_INITIATED') {
            return self::REASON_UNSUPPORTED_DIRECTION;
        }

        $timestamp = self::parseTimestamp($call['timestamp'] ?? null, fallback: Carbon::now()->getTimestamp());

        if ($timestamp === null) {
            return self::REASON_INVALID_TIMESTAMP;
        }

        $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
        $phoneNumberId = is_string($metadata['phone_number_id'] ?? null) && $metadata['phone_number_id'] !== ''
            ? $metadata['phone_number_id']
            : null;

        [$callerPhone, $callerName] = self::resolveCaller($call, $value, $metadata);

        $sdp = null;

        if ($event === self::EVENT_CONNECT) {
            $sdp = self::validateOffer($call['session'] ?? null);

            if ($sdp === self::REASON_MISSING_OFFER || $sdp === self::REASON_OVERSIZED_SDP || $sdp === self::REASON_INVALID_SDP) {
                return $sdp;
            }

            if ($callerPhone === null) {
                return self::REASON_UNUSABLE_CALLER;
            }
        }

        $errors = $value['errors'] ?? $call['errors'] ?? null;
        $hasErrors = is_array($errors) && $errors !== [];

        return new self(
            callId: $callId,
            event: $event,
            callerPhone: $callerPhone,
            callerName: $callerName,
            phoneNumberId: $phoneNumberId,
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

    /**
     * A call id made safe to write to a log: printable ASCII only, bounded in
     * length. Used for items that failed validation, whose id is untrusted.
     */
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

    /**
     * Meta only sends start_time/end_time/duration when the call was picked
     * up by the other party — the one documented "answered" signal.
     */
    public function pickedUp(): bool
    {
        return $this->startTime !== null;
    }

    /**
     * Whether this event's `metadata.phone_number_id` is compatible with the
     * source's configured one — a mismatch is only meaningful when both are
     * actually known.
     */
    public function matchesPhoneNumberId(mixed $configured): bool
    {
        if (! is_string($configured) || $configured === '' || $this->phoneNumberId === null) {
            return true;
        }

        return hash_equals($configured, $this->phoneNumberId);
    }

    /**
     * The value stored verbatim in lead_calls.meta_status: the Meta error code
     * when the payload carried errors, otherwise the normalized status token.
     */
    public function rawMetaStatus(): ?string
    {
        return $this->errorToken ?? $this->statusToken;
    }

    public function statusMentions(string $token): bool
    {
        return $this->statusToken !== null && str_contains($this->statusToken, $token);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'callId' => $this->callId,
            'event' => $this->event,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * @param  array<mixed>  $call
     * @param  array<mixed>  $value
     * @param  array<mixed>  $metadata
     * @return array{0: ?string, 1: ?string} [caller phone digits, contact display name]
     */
    private static function resolveCaller(array $call, array $value, array $metadata): array
    {
        $business = array_values(array_filter([
            self::digitsOf($metadata['display_phone_number'] ?? null),
            self::digitsOf($call['to'] ?? null),
        ]));

        $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];
        $candidates = [self::usablePhone($call['from'] ?? null)];

        foreach ($contacts as $contact) {
            $candidates[] = is_array($contact) ? self::usablePhone($contact['wa_id'] ?? null) : null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate === null || in_array($candidate, $business, true)) {
                continue;
            }

            return [$candidate, self::contactName($contacts, $candidate)];
        }

        return [null, null];
    }

    /**
     * @param  array<mixed>  $contacts
     */
    private static function contactName(array $contacts, string $phone): ?string
    {
        foreach ($contacts as $contact) {
            if (! is_array($contact) || ($contact['wa_id'] ?? null) !== $phone) {
                continue;
            }

            $name = is_array($contact['profile'] ?? null) ? ($contact['profile']['name'] ?? null) : null;

            return is_string($name) && $name !== '' ? $name : null;
        }

        return null;
    }

    private static function usablePhone(mixed $phone): ?string
    {
        return is_string($phone) && preg_match('/^\d{5,20}$/', $phone) === 1 ? $phone : null;
    }

    private static function digitsOf(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return is_string($digits) && $digits !== '' ? $digits : null;
    }

    /**
     * @return string the SDP offer, or one of the REASON_* constants
     */
    private static function validateOffer(mixed $session): string
    {
        if (! is_array($session) || ($session['sdp_type'] ?? null) !== 'offer') {
            return self::REASON_MISSING_OFFER;
        }

        $sdp = $session['sdp'] ?? null;

        if (! is_string($sdp) || trim($sdp) === '') {
            return self::REASON_MISSING_OFFER;
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
     * RFC 8866 ends every SDP line, the last included, with CRLF. Laravel's
     * TrimStrings middleware trims every string in a JSON webhook body, so the
     * SDP arrives here without the final terminator Meta sent. This restores
     * exactly that one terminator and nothing else: an SDP that already ends
     * with a line terminator is returned byte-for-byte, and no content line,
     * attribute, candidate or codec is ever touched. (A bare trailing CR — never
     * produced by Meta — is completed to CRLF rather than followed by a second
     * terminator.) The 16 KB limit is applied to the string as received, above.
     */
    private static function withFinalTerminator(string $sdp): string
    {
        if (str_ends_with($sdp, "\n")) {
            return $sdp;
        }

        return $sdp.(str_ends_with($sdp, "\r") ? "\n" : "\r\n");
    }

    private static function parseTimestamp(mixed $timestamp, int $fallback): ?int
    {
        if ($timestamp === null) {
            return $fallback;
        }

        if (is_int($timestamp)) {
            return $timestamp > 0 ? $timestamp : null;
        }

        if (is_string($timestamp) && ctype_digit($timestamp) && (int) $timestamp > 0) {
            return (int) $timestamp;
        }

        return null;
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
