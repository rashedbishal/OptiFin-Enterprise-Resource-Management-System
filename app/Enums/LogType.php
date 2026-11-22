<?php

namespace App\Enums;

/**
 * How a log file is split into entries (App\Services\Logs\LogIndex).
 */
enum LogType: string
{
    /** Laravel / Monolog: "[2026-11-22 08:00:00] production.ERROR: …", multi-line. */
    case Laravel = 'laravel';

    /** Console output of long-running workers (Horizon, Pulse, Reverb, queue). */
    case Worker = 'worker';

    /** Anything else: one entry per line. */
    case Generic = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::Worker => 'Worker',
            self::Generic => 'Text',
        };
    }
}
