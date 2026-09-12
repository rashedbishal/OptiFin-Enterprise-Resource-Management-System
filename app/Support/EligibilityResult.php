<?php

namespace App\Support;

/**
 * Feature #15 — the outcome of OutboundEligibilityService::evaluate(). A
 * plain result object (not a bool/exception) so the UI can show the exact
 * reason a compose action is disabled, and so the same check can be
 * re-run server-side inside the send job without needing to re-derive the
 * message text.
 */
final class EligibilityResult
{
    private function __construct(
        public readonly bool $eligible,
        public readonly ?string $reason,
    ) {}

    public static function eligible(): self
    {
        return new self(true, null);
    }

    public static function ineligible(string $reason): self
    {
        return new self(false, $reason);
    }
}
