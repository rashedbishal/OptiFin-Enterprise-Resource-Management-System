<?php

namespace App\Support;

/**
 * The outcome of one RequestLeadCallPermission attempt. A refusal (Meta's
 * own live actions[].limits[] says not allowed right now) is an expected
 * outcome the caller branches on, not an exception — same shape/reasoning as
 * LeadCallActionResult.
 */
final class RequestLeadCallPermissionResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly ?string $refusalReason,
        public readonly ?CallingApiResult $check,
    ) {}

    public static function sent(): self
    {
        return new self(true, null, null);
    }

    public static function refused(string $reason, ?CallingApiResult $check = null): self
    {
        return new self(false, $reason, $check);
    }
}
