<?php

namespace App\Support;

use App\Enums\CallingApiError;

/**
 * The outcome of one WhatsApp Calling API request. A plain immutable value
 * object: a Meta rejection is an expected outcome the caller branches on, not
 * an exception. It carries only what is safe to log and show — the app-owned
 * error category, Meta's numeric code / HTTP status / fbtrace_id for
 * diagnostics, and a small parsed `data` array the client built itself. It
 * never holds the access token, an SDP, a customer phone number or Meta's raw
 * error text, so it can be logged or dumped as-is.
 */
final class CallingApiResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public readonly bool $successful,
        public readonly ?CallingApiError $error,
        public readonly ?int $metaCode,
        public readonly ?int $httpStatus,
        public readonly ?string $fbtraceId,
        public readonly array $data,
        public readonly int $durationMs,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(int $httpStatus, array $data, int $durationMs): self
    {
        return new self(true, null, null, $httpStatus, null, $data, $durationMs);
    }

    public static function failure(
        CallingApiError $error,
        ?int $metaCode,
        ?int $httpStatus,
        ?string $fbtraceId,
        int $durationMs,
    ): self {
        return new self(false, $error, $metaCode, $httpStatus, $fbtraceId, [], $durationMs);
    }

    public function userMessage(): string
    {
        return $this->error?->userMessage() ?? '';
    }
}
