<?php

namespace App\Livewire\Platform\Monitoring;

use App\Enums\MonitoringTool;
use App\Enums\PermissionSlug;
use App\Livewire\Platform\Monitoring\Concerns\AuthorizesMonitoringTool;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\Jobs\RetryFailedJob;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use stdClass;
use Throwable;

/**
 * Admin → Monitoring → Horizon: queue status, throughput, workload and the
 * latest failed jobs, read from Horizon's own Redis repositories and shown
 * in the admin panel's design. The full native Horizon dashboard is linked
 * for anything deeper (job payloads, tags, metrics graphs).
 */
#[Title('Horizon')]
#[Layout('layouts.admin')]
class Horizon extends Component
{
    use AuthorizesMonitoringTool;

    private const FAILED_JOBS_SHOWN = 10;

    public function mount(): void
    {
        $this->authorizeMonitoring();
    }

    protected function monitoringTool(): MonitoringTool
    {
        return MonitoringTool::Horizon;
    }

    #[Computed]
    public function canRetry(): bool
    {
        return $this->allowsMonitoring(PermissionSlug::Update);
    }

    /**
     * Everything the page shows, in one read. Any Redis/Horizon failure (Redis
     * down, not configured) becomes an `error` the page explains, instead of
     * a 500 on a page whose whole point is diagnosing problems.
     *
     * @return array{error: string|null, status: string, jobsPerMinute: int, recentJobs: int, recentFailed: int, processes: int, workload: array<int, array{name: string, length: int, wait: int, processes: int}>, supervisors: array<int, array{name: string, status: string, processes: int}>, failedJobs: array<int, array{id: string, name: string, queue: string, failedAt: Carbon|null, exception: string}>}
     */
    #[Computed]
    public function snapshot(): array
    {
        $empty = [
            'error' => null, 'status' => 'inactive', 'jobsPerMinute' => 0, 'recentJobs' => 0, 'recentFailed' => 0,
            'processes' => 0, 'workload' => [], 'supervisors' => [], 'failedJobs' => [],
        ];

        try {
            $masters = collect(app(MasterSupervisorRepository::class)->all());
            $supervisors = collect(app(SupervisorRepository::class)->all());
            $jobs = app(JobRepository::class);

            return [
                ...$empty,
                'status' => match (true) {
                    $masters->isEmpty() => 'inactive',
                    $masters->every(fn (stdClass $master): bool => $master->status === 'paused') => 'paused',
                    default => 'running',
                },
                'jobsPerMinute' => (int) app(MetricsRepository::class)->jobsProcessedPerMinute(),
                'recentJobs' => (int) $jobs->countRecent(),
                'recentFailed' => (int) $jobs->countRecentlyFailed(),
                'processes' => (int) $supervisors->sum(fn (stdClass $supervisor): int => (int) array_sum((array) $supervisor->processes)),
                'workload' => collect(app(WorkloadRepository::class)->get())
                    ->map(fn (array $queue): array => [
                        'name' => (string) $queue['name'],
                        'length' => (int) $queue['length'],
                        'wait' => (int) $queue['wait'],
                        'processes' => (int) $queue['processes'],
                    ])->values()->all(),
                'supervisors' => $supervisors
                    ->map(fn (stdClass $supervisor): array => [
                        'name' => Str::after((string) $supervisor->name, ':'),
                        'status' => (string) $supervisor->status,
                        'processes' => (int) array_sum((array) $supervisor->processes),
                    ])->values()->all(),
                'failedJobs' => collect($jobs->getFailed())
                    ->take(self::FAILED_JOBS_SHOWN)
                    ->map(fn (stdClass $job): array => [
                        'id' => (string) $job->id,
                        'name' => (string) $job->name,
                        'queue' => (string) $job->queue,
                        'failedAt' => is_numeric($job->failed_at ?? null) ? Carbon::createFromTimestamp((float) $job->failed_at) : null,
                        'exception' => Str::limit(Str::before((string) ($job->exception ?? ''), "\n"), 160),
                    ])->values()->all(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [...$empty, 'error' => __('Horizon data is unavailable: :message', ['message' => Str::limit($exception->getMessage(), 200)])];
        }
    }

    /**
     * Re-queue one failed job — the same RetryFailedJob Horizon's own
     * dashboard dispatches. Needs the `update` permission on the Horizon module.
     */
    public function retry(string $jobId): void
    {
        $this->authorizeMonitoring(PermissionSlug::Update);

        if (app(JobRepository::class)->findFailed($jobId) === null) {
            Flux::toast(variant: 'danger', text: __('That failed job no longer exists.'));

            return;
        }

        dispatch(new RetryFailedJob($jobId));

        unset($this->snapshot);
        Flux::toast(variant: 'success', text: __('Job sent back to the queue.'));
    }
}
