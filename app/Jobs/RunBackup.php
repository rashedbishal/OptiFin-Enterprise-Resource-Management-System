<?php

namespace App\Jobs;

use App\Services\BackupSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * "Back up now" on Admin → Settings → Backups: runs the same `backup:run`
 * the nightly schedule runs, in the background. A lock stops two runs from
 * overlapping (e.g. a double click, or the nightly run at the same moment).
 * Failures are emailed by the backup package itself, like the nightly run.
 */
class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Below the queue's 90-second retry window, so a slow run is never
     * picked up a second time. Today's backup takes a few seconds.
     */
    public int $timeout = 85;

    public function handle(BackupSettings $settings): void
    {
        if (! $settings->isConfigured()) {
            return;
        }

        Cache::lock('backups:run', 600)->get(function () use ($settings): void {
            Artisan::call('backup:run');
            $settings->forgetStatus();
        });
    }
}
