<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('leads:fetch-emails')->everyMinute()->withoutOverlapping();
Schedule::command('leads:cleanup-attachments')->daily();
Schedule::command('leads:send-alert-notifications')->dailyAt('08:00');
Schedule::command('leads:recalculate-scores')->daily();
Schedule::command('leads:detect-duplicates')->daily();
Schedule::command('tasks:send-alert-notifications')->dailyAt('08:00');
Schedule::command('leads:nurture-inactive-leads')->dailyAt('08:00')->withoutOverlapping();
