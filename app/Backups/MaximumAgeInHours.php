<?php

namespace App\Backups;

use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Monitor\HealthCheck;

/**
 * `backup:monitor` health rule: unhealthy when there is no backup, or the
 * newest one is older than N hours. The package's own MaximumAgeInDays only
 * counts whole days, which would let a missed nightly backup go unnoticed
 * for almost two days.
 */
class MaximumAgeInHours extends HealthCheck
{
    public function __construct(protected int $hours = 26) {}

    public function checkHealth(BackupDestination $backupDestination): void
    {
        $this->failIf(
            $backupDestination->backups()->isEmpty(),
            trans('backup::notifications.unhealthy_backup_found_empty')
        );

        $newest = $backupDestination->backups()->newest();

        $this->failIf(
            $newest !== null && $newest->date()->lt(now()->subHours($this->hours)),
            trans('backup::notifications.unhealthy_backup_found_old', ['date' => $newest?->date()->format('Y/m/d H:i:s')])
        );
    }
}
