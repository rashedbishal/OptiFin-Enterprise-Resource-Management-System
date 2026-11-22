<?php

use App\Backups\MaximumAgeInHours;
use App\Notifications\Backups\BackupHasFailedNotification;
use App\Notifications\Backups\CleanupHasFailedNotification;
use App\Notifications\Backups\UnhealthyBackupWasFoundNotification;
use App\Support\BackupAlertNotifiable;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
 * Database + uploaded-file backups (spatie/laravel-backup), stored on
 * Backblaze B2. The B2 credentials and the archive password are managed by
 * the Mother Admin at Admin → Settings → Backups (App\Services\BackupSettings)
 * and applied at runtime: the `backups` disk and `backup.password` below are
 * filled in from there. Until that page is saved, the scheduled backup
 * commands don't run.
 */
return [

    'backup' => [
        // Folder name on the B2 bucket.
        'name' => env('BACKUP_NAME', 'optifin-erp'),

        'source' => [
            'files' => [
                // Uploaded files only (lead attachments, outbound media, the
                // ringtone, the calling app APK). Code comes from git; .env and
                // its APP_KEY are deliberately NOT included — keep APP_KEY in a
                // password manager.
                'include' => [
                    storage_path('app'),
                ],

                'exclude' => [
                    storage_path('app/backup-temp'),
                ],

                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => storage_path('app'),
            ],

            'databases' => [
                env('DB_CONNECTION', 'pgsql'),
            ],
        ],

        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',
            'disks' => [
                'backups',
            ],
            'continue_on_failure' => false,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        // Set from Admin → Settings → Backups; AES-256 encrypted archives.
        'password' => null,
        'encryption' => 'default',

        'verify_backup' => true,
        'tries' => 2,
        'retry_delay' => 60,
    ],

    /*
     * Emails only when something goes wrong, through the "Monitoring alerts"
     * account (Admin → Settings → Email settings) to its recipient list. The
     * success notifications are listed with no channels so they never send.
     */
    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => BackupAlertNotifiable::class,

        'mail' => [
            // Never used for sending: App\Support\BackupAlertNotifiable picks
            // the recipients (Monitoring alerts list, else OPS_ALERT_EMAIL).
            // The package only insists this is a syntactically valid address.
            'to' => 'backup-alerts@example.invalid',

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],

        'webhook' => [
            'url' => '',
        ],
    ],

    'log_channel' => null,

    /*
     * `backup:monitor` (scheduled daily at 04:30 Bangladesh time, after the 02:00 backup):
     * unhealthy when there is no backup newer than 26 hours.
     */
    'monitor_backups' => [
        [
            'name' => env('BACKUP_NAME', 'optifin-erp'),
            'disks' => ['backups'],
            'health_checks' => [
                MaximumAgeInHours::class => 26,
                MaximumStorageInMegabytes::class => 8000,
            ],
        ],
    ],

    /*
     * Keep every backup for 7 days, then one per day for 30 days, one per
     * week for 3 months and one per month for a year.
     */
    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 30,
            'keep_weekly_backups_for_weeks' => 12,
            'keep_monthly_backups_for_months' => 12,
            'keep_yearly_backups_for_years' => 0,
            // Safety net well under Backblaze B2's 10 GB free tier.
            'delete_oldest_backups_when_using_more_megabytes_than' => 8000,
        ],

        'tries' => 1,
        'retry_delay' => 0,
    ],

];
