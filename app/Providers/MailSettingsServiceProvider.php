<?php

namespace App\Providers;

use App\Services\MailSettings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Applies the email accounts saved at Admin → Settings → Email settings
 * (App\Services\MailSettings). Nothing saved = nothing changes.
 */
class MailSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailSettings::class);
    }

    public function boot(): void
    {
        // Lazily, only in a process that actually sends mail, and before any
        // mailer is built from config.
        $this->callAfterResolving('mail.manager', function (): void {
            $this->app->make(MailSettings::class)->applyToConfig();
        });

        // Long-running Horizon workers pick up a change within a minute.
        Queue::before(function (): void {
            $this->app->make(MailSettings::class)->refreshIfStale();
        });
    }
}
