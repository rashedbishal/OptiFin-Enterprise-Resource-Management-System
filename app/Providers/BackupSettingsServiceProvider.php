<?php

namespace App\Providers;

use App\Services\BackupSettings;
use Illuminate\Support\ServiceProvider;
use Spatie\Backup\Config\Config as BackupConfig;

/**
 * Applies the Backblaze B2 storage + archive password saved at Admin →
 * Settings → Backups (App\Services\BackupSettings) right before the backup
 * package builds its configuration, so every backup command and job uses the
 * current settings without a restart.
 */
class BackupSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BackupSettings::class);
    }

    public function boot(): void
    {
        $this->app->beforeResolving(BackupConfig::class, function (): void {
            $this->app->make(BackupSettings::class)->applyToConfig();
        });
    }
}
