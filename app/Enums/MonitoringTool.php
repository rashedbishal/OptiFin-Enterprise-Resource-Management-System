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

    public function label(): string
    {
        return match ($this) {
            self::Horizon => 'Horizon',
            self::Health => 'Health',
            self::Pulse => 'Pulse',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Horizon => 'Queue workers, job throughput and failed jobs (Laravel Horizon).',
            self::Health => 'Application health checks: database, cache, Redis, queue, scheduler and disk (Spatie Laravel Health).',
            self::Pulse => 'Application performance: slow requests, queries and jobs, exceptions and server load (Laravel Pulse).',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Horizon => 'queue-list',
            self::Health => 'heart',
            self::Pulse => 'chart-bar',
        };
    }

    public function routeName(): string
    {
        return 'admin.monitoring.'.$this->value;
    }
}
