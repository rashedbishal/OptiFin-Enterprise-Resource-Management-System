<?php

namespace App\Health;

use App\Services\BackupSettings;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Health page row for the B2 backups: fails when there is no backup or the
 * newest is older than 26 hours. Uses BackupSettings' cached status (refreshed
 * at most every 30 minutes) to stay inside B2's free daily API allowance.
 * "Not set up" is a warning WITHOUT a notification message, so it shows on the
 * page but never triggers an alert email.
 */
class BackupsCheck extends Check
{
    private const MAX_AGE_HOURS = 26;

    public function run(): Result
    {
        $settings = app(BackupSettings::class);

        if (! $settings->isConfigured()) {
            return Result::make()->warning()->shortSummary(__('Not set up'));
        }

        try {
            $status = $settings->status();
        } catch (Throwable $exception) {
            return Result::make()->failed(__('Backblaze B2 could not be reached: :error', ['error' => $exception->getMessage()]))->shortSummary(__('Unreachable'));
        }

        $result = Result::make()->meta(['count' => $status['count'] ?? 0]);
        $newestAt = $status['newestAt'] ?? null;

        if ($newestAt === null) {
            return $result->failed(__('No backup has been made yet.'))->shortSummary(__('No backups'));
        }

        if ($newestAt->lt(now()->subHours(self::MAX_AGE_HOURS))) {
            return $result->failed(__('The newest backup is from :time — older than :hours hours.', ['time' => $newestAt->diffForHumans(), 'hours' => self::MAX_AGE_HOURS]))->shortSummary(__('Too old'));
        }

        return $result->ok()->shortSummary(__('Latest :time', ['time' => $newestAt->diffForHumans()]));
    }
}
