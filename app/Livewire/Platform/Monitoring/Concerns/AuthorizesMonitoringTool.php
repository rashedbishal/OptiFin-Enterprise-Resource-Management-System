<?php

namespace App\Livewire\Platform\Monitoring\Concerns;

use App\Enums\MonitoringTool;
use App\Enums\PermissionSlug;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\Auth;

/**
 * Shared access check for the monitoring pages: `view` to open a page,
 * `update` for its actions, both through the tool's platform module on the
 * admin's Role (MonitoringTool / PlatformUser::canUseMonitoringTool()).
 * Reads the `platform` guard explicitly so a tenant session in the same
 * browser never decides access.
 */
trait AuthorizesMonitoringTool
{
    abstract protected function monitoringTool(): MonitoringTool;

    protected function authorizeMonitoring(PermissionSlug $permission = PermissionSlug::View): void
    {
        abort_unless($this->allowsMonitoring($permission), 403);
    }

    protected function allowsMonitoring(PermissionSlug $permission = PermissionSlug::View): bool
    {
        $admin = Auth::guard('platform')->user();

        return $admin instanceof PlatformUser && $admin->canUseMonitoringTool($this->monitoringTool(), $permission);
    }
}
