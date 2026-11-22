<?php

namespace App\Enums;

/**
 * Severity of one log entry (PSR-3 levels), as shown on Admin → Monitoring →
 * Logs. Worker logs (Horizon, Pulse, Reverb) are mapped onto these too.
 */
enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert = 'alert';
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
    case Debug = 'debug';

    public static function fromName(string $name): self
    {
        return self::tryFrom(strtolower($name)) ?? match (strtoupper($name)) {
            'FAIL', 'FAILED', 'FATAL' => self::Error,
            'WARN' => self::Warning,
            default => self::Info,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Emergency, self::Alert, self::Critical, self::Error => 'red',
            self::Warning => 'yellow',
            self::Notice => 'blue',
            self::Info => 'green',
            self::Debug => 'zinc',
        };
    }

    public function isProblem(): bool
    {
        return in_array($this, [self::Emergency, self::Alert, self::Critical, self::Error], true);
    }
}
