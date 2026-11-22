<?php

namespace App\Providers;

use App\Enums\MonitoringTool;
use App\Health\BackupsCheck;
use App\Models\PlatformUser;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DatabaseConnectionCountCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
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
    public function boot(): void
    {
        $this->configurePulseGate();
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
        ];

        if (config('database.default') === 'pgsql') {
            $checks[] = DatabaseConnectionCountCheck::new()->warnWhenMoreConnectionsThan(50)->failWhenMoreConnectionsThan(90);
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
