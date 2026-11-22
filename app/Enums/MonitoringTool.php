<?php

namespace App\Enums;

/**
 * The Platform-side monitoring tools. Each one is a platform-scoped Module
 * (the case value is the module slug), so the Mother Admin grants access per
 * admin through that admin's Role, like every other platform module:
 * `view` opens the page, `update` allows its actions (retrying failed jobs,
 * running health checks).
 */
enum MonitoringTool: string
{
    case Horizon = 'horizon';
    case Health = 'health';
    case Pulse = 'pulse';
    case Integrations = 'integrations';
    case Logs = 'logs';

    public function label(): string
    {
        return match ($this) {
            self::Horizon => 'Horizon',
            self::Health => 'Health',
            self::Pulse => 'Pulse',
            self::Integrations => 'Integrations',
            self::Logs => 'Logs',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Horizon => 'Queue workers, job throughput and failed jobs (Laravel Horizon).',
            self::Health => 'Application health checks: database, cache, Redis, queue, scheduler and disk (Spatie Laravel Health).',
            self::Pulse => 'Application performance: slow requests, queries and jobs, exceptions and server load (Laravel Pulse).',
            self::Integrations => 'Health of every client\'s lead sources: last activity, Meta tokens, webhook rejections and email inboxes.',
            self::Logs => 'Read and search the application log files (opcodesio/log-viewer); Update also allows downloading and deleting them.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Horizon => 'queue-list',
            self::Health => 'heart',
            self::Pulse => 'chart-bar',
            self::Integrations => 'puzzle-piece',
            self::Logs => 'document-text',
        };
    }

    public function routeName(): string
    {
        return match ($this) {
            // The log viewer package's own screen (config/log-viewer.php).
            self::Logs => 'log-viewer.index',
            default => 'admin.monitoring.'.$this->value,
        };
    }
}
