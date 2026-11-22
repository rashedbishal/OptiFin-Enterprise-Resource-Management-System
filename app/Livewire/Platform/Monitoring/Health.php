<?php

namespace App\Livewire\Platform\Monitoring;

use App\Enums\MonitoringTool;
use App\Enums\PermissionSlug;
use App\Livewire\Platform\Monitoring\Concerns\AuthorizesMonitoringTool;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Throwable;

/**
 * Admin → Monitoring → Health: the latest results of the Spatie Laravel
 * Health checks registered in MonitoringServiceProvider (run every minute by
 * the scheduled `health:check`), with an on-demand re-run for admins holding
 * the Health module's `update` permission.
 */
#[Title('Health')]
#[Layout('layouts.admin')]
class Health extends Component
{
    use AuthorizesMonitoringTool;

    public function mount(): void
    {
        $this->authorizeMonitoring();
    }

    protected function monitoringTool(): MonitoringTool
    {
        return MonitoringTool::Health;
    }

    #[Computed]
    public function canRunChecks(): bool
    {
        return $this->allowsMonitoring(PermissionSlug::Update);
    }

    /**
     * @return array{finishedAt: Carbon|null, checks: array<int, array{name: string, label: string, status: string, summary: string, message: string}>, counts: array{ok: int, warning: int, failed: int}}
     */
    #[Computed]
    public function results(): array
    {
        $latest = app(ResultStore::class)->latestResults();

        $checks = collect($latest === null ? [] : $latest->storedCheckResults)
            ->map(fn (StoredCheckResult $check): array => [
                'name' => $check->name,
                'label' => $check->label !== '' ? $check->label : $check->name,
                'status' => $check->status,
                'summary' => $check->shortSummary,
                'message' => (string) $check->notificationMessage,
            ])
            // Problems first, so a failing check is never below the fold.
            ->sortBy(fn (array $check): int => match ($check['status']) {
                'failed', 'crashed' => 0,
                'warning' => 1,
                default => 2,
            })
            ->values();

        return [
            'finishedAt' => $latest ? Carbon::instance($latest->finishedAt) : null,
            'checks' => $checks->all(),
            'counts' => [
                'ok' => $checks->where('status', 'ok')->count(),
                'warning' => $checks->where('status', 'warning')->count(),
                'failed' => $checks->whereIn('status', ['failed', 'crashed'])->count(),
            ],
        ];
    }

    /**
     * Re-runs every check now instead of waiting for the next scheduled run.
     * Notifications are skipped — the scheduled run owns alerting, so an
     * admin poking the button never triggers an extra failure email.
     */
    public function runChecks(): void
    {
        $this->authorizeMonitoring(PermissionSlug::Update);

        try {
            Artisan::call('health:check', ['--no-notification' => true]);
        } catch (Throwable $exception) {
            report($exception);
            Flux::toast(variant: 'danger', text: __('The health checks could not be run.'));

            return;
        }

        unset($this->results);
        Flux::toast(variant: 'success', text: __('Health checks finished.'));
    }
}
