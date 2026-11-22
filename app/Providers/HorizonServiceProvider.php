<?php

namespace App\Providers;

use App\Enums\MonitoringTool;
use App\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Horizon's stock authorization lets everyone in when APP_ENV=local and
     * checks the gate against the default (`web`, tenant) guard. Neither fits:
     * the native dashboard is a Platform tool, granted per admin through the
     * `horizon` platform module, in every environment.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => Gate::check('viewHorizon'));
    }

    /**
     * The viewHorizon gate. Its user argument is the default-guard (tenant)
     * user, so the platform admin is read from the `platform` guard instead —
     * a tenant session in the same browser never grants or blocks access.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (mixed $user = null): bool {
            $admin = Auth::guard('platform')->user();

            return $admin instanceof PlatformUser && $admin->canUseMonitoringTool(MonitoringTool::Horizon);
        });
    }
}
