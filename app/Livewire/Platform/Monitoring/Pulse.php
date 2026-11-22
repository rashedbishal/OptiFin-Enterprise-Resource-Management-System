<?php

namespace App\Livewire\Platform\Monitoring;

use App\Enums\MonitoringTool;
use App\Livewire\Platform\Monitoring\Concerns\AuthorizesMonitoringTool;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Pulse\Facades\Pulse as PulseData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use stdClass;
use Throwable;

/**
 * Admin → Monitoring → Pulse: a summary of what Laravel Pulse recorded —
 * servers, slow requests/queries/jobs, exceptions, queues, cache and the
 * busiest users — queried from Pulse's storage and shown in the admin
 * panel's design. The native Pulse dashboard is linked for its graphs.
 */
#[Title('Pulse')]
#[Layout('layouts.admin')]
class Pulse extends Component
{
    use AuthorizesMonitoringTool;

    /**
     * @var array<string, int> period key => hours
     */
    public const PERIODS = ['1_hour' => 1, '6_hours' => 6, '24_hours' => 24, '7_days' => 168];

    private const ROWS = 10;

    #[Url]
    public string $period = '1_hour';

    public function mount(): void
    {
        $this->authorizeMonitoring();

        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = '1_hour';
        }
    }

    protected function monitoringTool(): MonitoringTool
    {
        return MonitoringTool::Pulse;
    }

    public function updatedPeriod(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = '1_hour';
        }
    }

    private function interval(): CarbonInterval
    {
        return CarbonInterval::hours(self::PERIODS[$this->period] ?? 1);
    }

    /**
     * Everything the page shows. A storage failure becomes an `error` the
     * page explains rather than a 500.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function summary(): array
    {
        try {
            $interval = $this->interval();
            $cache = PulseData::aggregateTotal(['cache_hit', 'cache_miss'], 'count', $interval);
            $cacheHits = (int) ($cache['cache_hit'] ?? 0);
            $cacheTotal = $cacheHits + (int) ($cache['cache_miss'] ?? 0);
            $usage = PulseData::aggregate('user_request', 'count', $interval, limit: self::ROWS);
            $users = PulseData::resolveUsers($usage->pluck('key'));

            return [
                'error' => null,
                'servers' => $this->servers(),
                'cacheHitRate' => $cacheTotal > 0 ? round($cacheHits / $cacheTotal * 100, 1) : null,
                'exceptionCount' => $this->total('exception'),
                'slowRequestCount' => $this->total('slow_request'),
                'failedJobCount' => $this->total('failed'),
                'slowRequests' => $this->rows('slow_request', fn (?array $key): array => ['method' => $key[0] ?? '', 'uri' => $key[1] ?? '']),
                'slowQueries' => $this->rows('slow_query', fn (?array $key): array => ['sql' => Str::limit((string) ($key[0] ?? ''), 140), 'location' => $key[1] ?? '']),
                'slowJobs' => $this->rows('slow_job', fn (?array $key, string $raw): array => ['job' => $raw]),
                'exceptions' => $this->rows('exception', fn (?array $key): array => ['class' => $key[0] ?? '', 'location' => $key[1] ?? ''], aggregates: ['max', 'count'], orderBy: 'count'),
                'queues' => PulseData::aggregateTypes(['queued', 'processed', 'released', 'failed'], 'count', $interval)
                    ->map(fn (stdClass $row): array => [
                        'queue' => Str::after((string) $row->key, ':'),
                        'queued' => (int) ($row->queued ?? 0),
                        'processed' => (int) ($row->processed ?? 0),
                        'released' => (int) ($row->released ?? 0),
                        'failed' => (int) ($row->failed ?? 0),
                    ])->values()->all(),
                'usage' => $usage->map(function (stdClass $row) use ($users): array {
                    $user = $users->find($row->key);

                    return ['name' => $user->name, 'email' => $user->extra ?? '', 'count' => (int) $row->count];
                })->values()->all(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['error' => __('Pulse data is unavailable: :message', ['message' => Str::limit($exception->getMessage(), 200)])];
        }
    }

    /**
     * Count of one entry type over the selected period. aggregateTotal()
     * returns a float for a single type (a Collection only for several).
     */
    private function total(string $type): int
    {
        $total = PulseData::aggregateTotal($type, 'count', $this->interval());

        return $total instanceof Collection ? (int) $total->sum() : (int) $total;
    }

    /**
     * Top rows of one slow-entry or exception type, with its JSON key decoded by $describe.
     *
     * @param  list<string>  $aggregates
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $type, callable $describe, array $aggregates = ['max', 'count'], string $orderBy = 'max'): array
    {
        return PulseData::aggregate($type, $aggregates, $this->interval(), $orderBy, limit: self::ROWS)
            ->map(function (stdClass $row) use ($describe): array {
                $decoded = json_decode((string) $row->key, true);

                return [
                    ...$describe(is_array($decoded) ? $decoded : null, (string) $row->key),
                    'count' => (int) ($row->count ?? 0),
                    'slowest' => isset($row->max) ? (int) $row->max : null,
                ];
            })->values()->all();
    }

    /**
     * Latest snapshot per server, as reported by `pulse:check`.
     *
     * @return array<int, array{name: string, cpu: int, memoryUsed: int, memoryTotal: int, storage: array<int, array{directory: string, used: int, total: int}>, updatedAt: CarbonImmutable, recentlyReported: bool}>
     */
    private function servers(): array
    {
        return PulseData::values('system')
            ->map(function (stdClass $system): ?array {
                $values = json_decode((string) $system->value, true);

                if (! is_array($values)) {
                    return null;
                }

                $updatedAt = CarbonImmutable::createFromTimestamp((int) $system->timestamp);

                return [
                    'name' => (string) ($values['name'] ?? '—'),
                    'cpu' => (int) ($values['cpu'] ?? 0),
                    'memoryUsed' => (int) ($values['memory_used'] ?? 0),
                    'memoryTotal' => (int) ($values['memory_total'] ?? 0),
                    'storage' => (new Collection($values['storage'] ?? []))
                        ->map(fn (array $disk): array => ['directory' => (string) ($disk['directory'] ?? ''), 'used' => (int) ($disk['used'] ?? 0), 'total' => (int) ($disk['total'] ?? 0)])
                        ->values()->all(),
                    'updatedAt' => $updatedAt,
                    'recentlyReported' => $updatedAt->isAfter(now()->subSeconds(30)),
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values()
            ->all();
    }
}
