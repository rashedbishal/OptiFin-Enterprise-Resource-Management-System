<?php

namespace App\Support;

use App\Actions\Tenant\RecordInboundCall;
use App\Models\LeadSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * WhatsApp inbound calling — the one place the claim / answer / accept steps
 * touch an SDP. The SDP offer (written by Step 2) and the claimant's SDP answer
 * only ever live in the cache, encrypted, keyed by tenant + source + Meta call
 * id, for a bounded TTL — never on a lead_calls row, never in a log.
 *
 * The answer matters twice: Meta requires the `accept` SDP to equal the
 * `pre_accept` SDP, so the answer is validated and normalized ONCE (here, at
 * `answer`), cached, and `accept` reads the cached copy instead of taking a new
 * one from the browser.
 */
final class LeadCallSdp
{
    public const int MAX_BYTES = WhatsAppCallEvent::MAX_SDP_BYTES;

    /**
     * Validates an inbound answer's size and shape, then restores its final
     * line terminator. Laravel's TrimStrings middleware trims every request
     * string, so the SDP arrives without the CRLF RFC 8866 ends its last line
     * with — the same trap as the offer in Step 2, fixed the same way: exactly
     * one terminator is restored and nothing else about the SDP is touched.
     *
     * @return string|null the normalized SDP, or null when it is not an acceptable SDP
     */
    public static function normalizeAnswer(mixed $sdp): ?string
    {
        if (! is_string($sdp) || $sdp === '' || strlen($sdp) > self::MAX_BYTES) {
            return null;
        }

        if (! str_starts_with($sdp, 'v=0')) {
            return null;
        }

        // NUL and other control characters (everything but tab, LF, CR) never
        // occur in a real SDP.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sdp) === 1) {
            return null;
        }

        if (preg_match('/^m=/m', $sdp) !== 1) {
            return null;
        }

        if (str_ends_with($sdp, "\n")) {
            return $sdp;
        }

        return $sdp.(str_ends_with($sdp, "\r") ? "\n" : "\r\n");
    }

    /**
     * WhatsApp Outbound Calling — validates the browser's own SDP offer
     * before it is ever sent to Meta's `/calls` connect action. Identical
     * checks to normalizeAnswer() (a well-formed SDP is a well-formed SDP
     * regardless of direction) kept as its own method rather than a shared
     * rename, so normalizeAnswer() — and the hardening coverage already
     * built around it — stays completely untouched.
     *
     * @return string|null the normalized SDP, or null when it is not an acceptable SDP
     */
    public static function normalizeOffer(mixed $sdp): ?string
    {
        if (! is_string($sdp) || $sdp === '' || strlen($sdp) > self::MAX_BYTES) {
            return null;
        }

        if (! str_starts_with($sdp, 'v=0')) {
            return null;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sdp) === 1) {
            return null;
        }

        if (preg_match('/^m=/m', $sdp) !== 1) {
            return null;
        }

        if (str_ends_with($sdp, "\n")) {
            return $sdp;
        }

        return $sdp.(str_ends_with($sdp, "\r") ? "\n" : "\r\n");
    }

    public static function answerKey(LeadSource $source, string $callId): string
    {
        return "wa-call-answer:{$source->tenant_id}:{$source->id}:{$callId}";
    }

    public static function readOffer(LeadSource $source, string $callId): ?string
    {
        return self::read(RecordInboundCall::offerCacheKey($source, $callId));
    }

    public static function readAnswer(LeadSource $source, string $callId): ?string
    {
        return self::read(self::answerKey($source, $callId));
    }

    /**
     * Encrypts and caches the normalized answer, retrying once.
     */
    public static function storeAnswer(LeadSource $source, string $callId, string $sdp): bool
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                if (Cache::put(
                    self::answerKey($source, $callId),
                    Crypt::encryptString($sdp),
                    Carbon::now()->addSeconds((int) config('calling.answer_ttl_seconds')),
                )) {
                    return true;
                }
            } catch (Throwable) {
                // Retried once, then reported by the caller.
            }
        }

        return false;
    }

    /**
     * Drops both cached SDPs once the call can no longer use them. Best effort:
     * the TTL bounds them anyway.
     */
    public static function forget(LeadSource $source, string $callId): void
    {
        try {
            Cache::forget(RecordInboundCall::offerCacheKey($source, $callId));
            Cache::forget(self::answerKey($source, $callId));
        } catch (Throwable) {
            // Expires on its own.
        }
    }

    private static function read(string $key): ?string
    {
        try {
            $value = Cache::get($key);

            return is_string($value) ? Crypt::decryptString($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
