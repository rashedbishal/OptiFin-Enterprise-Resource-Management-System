<?php

namespace App\Support;

/**
 * The outcome of one Platform Admin calling action (enable / disable / check),
 * ready for the UI: a toast variant, a safe message, and the transient
 * eligibility details parsed from Meta's settings (never persisted — see
 * ManageWhatsAppCalling). Holds no credentials and none of Meta's raw text.
 */
final class CallingActionResult
{
    /**
     * @param  'success'|'warning'|'danger'  $variant
     * @param  array<int, array{type: string, expiration: ?int}>  $restrictions
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly string $variant,
        public readonly string $message,
        public readonly array $restrictions = [],
        public readonly array $warnings = [],
    ) {}
}
