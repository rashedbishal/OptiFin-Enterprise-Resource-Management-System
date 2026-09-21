<?php

namespace App\Support;

use App\Models\LeadCall;

/**
 * The outcome of one claim / answer / accept / reject request, ready for the
 * controller to render. A refusal is an expected outcome, so it is a value, not
 * an exception. It carries no credential, no Meta text and — except the offer
 * a successful claim hands its claimant — no SDP.
 */
final class LeadCallActionResult
{
    /**
     * @param  array{sdp_type: string, sdp: string}|null  $session
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?LeadCall $call,
        public readonly ?array $session,
        public readonly ?bool $metaConfirmed,
        public readonly ?string $errorCode,
        public readonly ?string $message,
        public readonly bool $retryable,
    ) {}

    /**
     * @param  array{sdp_type: string, sdp: string}|null  $session
     */
    public static function success(LeadCall $call, ?array $session = null, ?bool $metaConfirmed = null): self
    {
        return new self(true, 200, $call, $session, $metaConfirmed, null, null, false);
    }

    public static function error(int $status, string $code, string $message, bool $retryable = false): self
    {
        return new self(false, $status, null, null, null, $code, $message, $retryable);
    }

    public static function notFound(): self
    {
        return self::error(404, 'call_not_found', __('Call not found.'));
    }
}
