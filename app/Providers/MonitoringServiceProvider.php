<?php

namespace App\Providers;

use App\Enums\MonitoringTool;
use App\Enums\PermissionSlug;
use App\Health\BackupsCheck;
use App\Health\DatabaseSizeCheck;
use App\Health\EmailInboxesCheck;
use App\Health\FailedJobsCheck;
use App\Health\MetaTokensCheck;
use App\Models\PlatformUser;
use App\Services\ErrorAlertNotifier;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DatabaseConnectionCountCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\RedisMemoryUsageCheck;
use Spatie\Health\Checks\Checks\ReverbCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

/**
 * Platform monitoring: Horizon (queues), Pulse (performance) and Spatie
 * Laravel Health (health checks). Access to each is a platform module
 * granted per admin through their Role — see App\Enums\MonitoringTool.
 * Horizon's own gate lives in HorizonServiceProvider.
 */
class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per process, so its re-entry guard works.
        $this->app->singleton(ErrorAlertNotifier::class);
    }

    public function boot(): void
    {
        $this->configurePulseGate();
        $this->configureLogViewer();
        $this->configureHealthChecks();
        $this->configureDevCommands();
    }

    /**
     * Pulse's default gate allows everyone when APP_ENV=local and receives the
     * default-guard (tenant) user. Replaced so the native Pulse dashboard is
     * always gated by the `pulse` platform module on the platform guard.
     */
    protected function configurePulseGate(): void
    {
        Gate::define('viewPulse', function (mixed $user = null): bool {
            $admin = Auth::guard('platform')->user();

            return $admin instanceof PlatformUser && $admin->canUseMonitoringTool(MonitoringTool::Pulse);
        });
    }

    /**
     * The log viewer: `view` on the "Logs" platform module opens it, `update`
     * also allows downloading and deleting log files. Always checked on the
     * platform guard (the gates' own $user is the default-guard tenant user).
     * These replace the package's allow-everything default gates.
     */
    protected function configureLogViewer(): void
    {
        $allowed = static function (PermissionSlug $permission): bool {
            $admin = Auth::guard('platform')->user();

            return $admin instanceof PlatformUser && $admin->canUseMonitoringTool(MonitoringTool::Logs, $permission);
        };

        LogViewer::auth(fn (): bool => $allowed(PermissionSlug::View));

        foreach (['downloadLogFile', 'downloadLogFolder', 'deleteLogFile', 'deleteLogFolder'] as $ability) {
            Gate::define($ability, fn (mixed $user = null, mixed $target = null): bool => $allowed(PermissionSlug::Update));
        }
    }

    /**
     * Run by the scheduled `health:check` command; results are shown on the
     * Health monitoring page. Checks that only make sense in production
     * (debug off, production env, cached config) are skipped elsewhere so
     * local development isn't permanently red.
     */
    protected function configureHealthChecks(): void
    {
        $checks = [
            DatabaseCheck::new(),
            CacheCheck::new(),
            RedisCheck::new(),
            HorizonCheck::new(),
            QueueCheck::new()->failWhenHealthJobTakesLongerThanMinutes(5),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(2),
            ReverbCheck::new()->timeout(2),
            UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(75)->failWhenUsedSpaceIsAbovePercentage(90),
            BackupsCheck::new()->label('Backups'),
            MetaTokensCheck::new()->label('Meta tokens'),
            EmailInboxesCheck::new()->label('Email inboxes'),
            FailedJobsCheck::new()->label('Failed jobs'),
            RedisMemoryUsageCheck::new()->label('Redis memory')->warnWhenAboveMb(1024)->failWhenAboveMb(2048),
        ];

        if (config('database.default') === 'pgsql') {
            $checks[] = DatabaseConnectionCountCheck::new()->warnWhenMoreConnectionsThan(50)->failWhenMoreConnectionsThan(90);
            $checks[] = DatabaseSizeCheck::new()->label('Database size');
        }

        if ($this->app->isProduction()) {
            $checks[] = DebugModeCheck::new();
            $checks[] = EnvironmentCheck::new();
            $checks[] = OptimizedAppCheck::new();
        }

        Health::checks($checks);
    }

    /**
     * `composer run dev` / `php artisan dev`: Horizon registers its own entry
     * (replacing `queue:listen`); `pulse:check` is added so the Pulse server
     * card has data locally too.
     */
    protected function configureDevCommands(): void
    {
        if (! class_exists(DevCommands::class)) {
            return;
        }

        DevCommands::artisan('pulse:check', 'pulse');
    }
}
