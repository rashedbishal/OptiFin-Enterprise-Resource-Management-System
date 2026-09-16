<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('leads:fetch-emails')->everyMinute()->withoutOverlapping();
// EMAIL-LOSS-1 — deliberately much less frequent than the poll above: a
// failed message's own backoff (15min * attempts, see
// ProcessEmailLeadSource::RETRY_BACKOFF_MINUTES) already spaces out
// individual retries, so this only needs to run often enough to notice a
// newly-due record promptly, not to race the backoff itself.
Schedule::command('leads:retry-failed-emails')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('leads:cleanup-attachments')->daily();
Schedule::command('leads:send-alert-notifications')->dailyAt('08:00');
Schedule::command('leads:recalculate-scores')->daily();
Schedule::command('leads:detect-duplicates')->daily();
Schedule::command('tasks:send-alert-notifications')->dailyAt('08:00');
Schedule::command('leads:nurture-inactive-leads')->dailyAt('08:00')->withoutOverlapping();
