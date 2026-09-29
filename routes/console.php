<?php

use App\Services\ScheduledTaskMonitor;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// OPS-1 — every scheduled command is registered through this helper so a
// non-zero exit is always logged (and emailed, when OPS_ALERT_EMAIL is set) by
// ScheduledTaskMonitor. Laravel's own schedule:run already report()s such a
// failure, but nothing alerted anyone. onFailure() runs only after a run
// actually happened, so a run skipped by withoutOverlapping() is not a failure.
$monitored = static function (string $command): Event {
    $event = Schedule::command($command);

    $event->onFailure(fn () => app(ScheduledTaskMonitor::class)->failed($command, (int) $event->exitCode));

    return $event;
};

// OPS-1 — the scheduler liveness signal: OPS_SCHEDULER_HEARTBEAT_URL is pinged
// only when this every-minute command finishes successfully, and only when set.
// It proves schedule:run is executing — NOT that a queue worker is alive.
$monitored('leads:fetch-emails')->everyMinute()->withoutOverlapping()
    ->onSuccess(fn () => app(ScheduledTaskMonitor::class)->heartbeat());
// EMAIL-LOSS-1 — deliberately much less frequent than the poll above: a
// failed message's own backoff (15min * attempts, see
// ProcessEmailLeadSource::RETRY_BACKOFF_MINUTES) already spaces out
// individual retries, so this only needs to run often enough to notice a
// newly-due record promptly, not to race the backoff itself.
$monitored('leads:retry-failed-emails')->everyFifteenMinutes()->withoutOverlapping();
// F5 remediation (forensic audit Batch C) — these three previously had no
// overlap protection at all, unlike every other command in this file. Same
// $monitored() wrapper, same withoutOverlapping() call every already-guarded
// command here already uses — see that helper's own docblock for why a run
// skipped by withoutOverlapping() is never reported as a failure.
$monitored('leads:cleanup-attachments')->daily()->withoutOverlapping();
$monitored('leads:send-alert-notifications')->dailyAt('08:00');
$monitored('leads:recalculate-scores')->daily()->withoutOverlapping();
$monitored('leads:detect-duplicates')->daily()->withoutOverlapping();
$monitored('tasks:send-alert-notifications')->dailyAt('08:00');
$monitored('leads:nurture-inactive-leads')->dailyAt('08:00')->withoutOverlapping();
// WhatsApp calling Step 6 — ends calls whose terminate webhook never arrived.
// The overlap mutex is deliberately short (10 min, not the 24h default): a run
// killed mid-sweep must not stall reconciliation for a day. Correctness never
// depends on it — every transition is a compare-and-set.
$monitored('calls:reconcile-stale')->everyMinute()->withoutOverlapping(10);
