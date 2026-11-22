<?php

namespace App\Support\Logs;

use App\Enums\LogType;
use Carbon\CarbonImmutable;

/**
 * One log file under storage/logs, as listed on Admin → Monitoring → Logs.
 * `id` is derived from the relative path and is the only way the page refers
 * to a file, so a request can never name an arbitrary path.
 */
final readonly class LogFile
{
    public function __construct(
        public string $id,
        public string $path,
        public string $relativePath,
        public string $folder,
        public string $name,
        public int $size,
        public CarbonImmutable $modifiedAt,
        public LogType $type,
    ) {}

    public static function idFor(string $relativePath): string
    {
        return substr(sha1($relativePath), 0, 16);
    }
}
