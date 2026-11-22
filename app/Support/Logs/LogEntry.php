<?php

namespace App\Support\Logs;

use App\Enums\LogLevel;
use Carbon\CarbonImmutable;

/**
 * One entry in a log file: where it is, its level and time, and a one-line
 * preview. The full text is read only when the entry is opened.
 */
final readonly class LogEntry
{
    public function __construct(
        public string $fileId,
        public string $fileName,
        public int $offset,
        public int $length,
        public LogLevel $level,
        public ?CarbonImmutable $time,
        public ?string $environment,
        public string $preview,
        public bool $hasMoreLines,
    ) {}
}
