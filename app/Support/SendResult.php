<?php

namespace App\Support;

/**
 * Feature #15 — the outcome of one MessageSenderInterface::send() call. A
 * plain, immutable result object rather than throwing on failure: a
 * platform rejecting a message (bad recipient, policy violation, expired
 * window) is an expected, routine outcome the caller must record on the
 * Message row (status=failed + failure_reason), not an exceptional one —
 * only a genuine transport failure (network/timeout) should throw, which
 * the queued job's own retry handling is what needs to catch.
 */
final class SendResult
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $externalMessageId,
        public readonly ?string $failureReason,
        public readonly ?array $rawResponse,
    ) {}

    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public static function success(?string $externalMessageId, ?array $rawResponse = null): self
    {
        return new self(true, $externalMessageId, null, $rawResponse);
    }

    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public static function failure(string $reason, ?array $rawResponse = null): self
    {
        return new self(false, null, $reason, $rawResponse);
    }
}
