<?php

use App\Http\Controllers\MetaPermissionsController;
use App\Http\Controllers\Platform\CallingAppApkUploadController;
use App\Http\Controllers\Platform\LogDownloadController;
use App\Livewire\Actions\PlatformLogout;
use App\Livewire\Platform\Admins\Index as AdminsIndex;
use App\Livewire\Platform\Auth\Login;
use App\Livewire\Platform\Clients\Index as ClientsIndex;
use App\Livewire\Platform\Clients\LeadSources;
use App\Livewire\Platform\Clients\Show as ClientsShow;
use App\Livewire\Platform\Dashboard;
use App\Livewire\Platform\LeadManagement\Settings as LeadManagementSettings;
use App\Livewire\Platform\Modules\Index as ModulesIndex;
use App\Livewire\Platform\Monitoring\FailedJobs as MonitoringFailedJobs;
use App\Livewire\Platform\Monitoring\Health as MonitoringHealth;
use App\Livewire\Platform\Monitoring\Horizon as MonitoringHorizon;
use App\Livewire\Platform\Monitoring\Integrations as MonitoringIntegrations;
use App\Livewire\Platform\Monitoring\Logs as MonitoringLogs;
use App\Livewire\Platform\Monitoring\Pulse as MonitoringPulse;
use App\Livewire\Platform\Roles\Index as RolesIndex;
use App\Livewire\Platform\Settings\ActivityLog as SettingsActivityLog;
use App\Livewire\Platform\Settings\Backups as SettingsBackups;
use App\Livewire\Platform\Settings\CallingApp as SettingsCallingApp;
use App\Livewire\Platform\Settings\Email as SettingsEmail;
use App\Livewire\Platform\Settings\Password as SettingsPassword;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:platform')->group(function () {
        Route::livewire('login', Login::class)->name('login');
    });

    Route::middleware('auth:platform')->group(function () {
        Route::post('logout', PlatformLogout::class)->name('logout');

        Route::livewire('dashboard', Dashboard::class)->name('dashboard');

        Route::livewire('admins', AdminsIndex::class)->name('admins.index');
        Route::livewire('roles', RolesIndex::class)->name('roles.index');
        Route::livewire('clients', ClientsIndex::class)->name('clients.index');
        Route::livewire('clients/{tenant}', ClientsShow::class)->name('clients.show');
        Route::livewire('clients/{tenant}/lead-sources', LeadSources::class)->name('clients.lead-sources');
        Route::get('clients/{tenant}/lead-sources/{leadSource}/meta-permissions', [MetaPermissionsController::class, 'show'])->name('clients.lead-sources.meta-permissions');
        Route::livewire('modules', ModulesIndex::class)->name('modules.index');
        Route::livewire('lead-management', LeadManagementSettings::class)->name('lead-management.index');
        Route::livewire('settings/password', SettingsPassword::class)->name('settings.password');
        // Calling app's Firebase key — Mother Admin only (enforced in the component).
        Route::livewire('settings/calling-app', SettingsCallingApp::class)->name('settings.calling-app');
        // Email sending accounts — Mother Admin only (enforced in the component).
        Route::livewire('settings/email', SettingsEmail::class)->name('settings.email');
        // Backblaze B2 backups — Mother Admin only (enforced in the component).
        Route::livewire('settings/backups', SettingsBackups::class)->name('settings.backups');
        // Activity log — Mother Admin only (enforced in the component).
        Route::livewire('settings/activity-log', SettingsActivityLog::class)->name('settings.activity-log');
        // Chunked APK upload for the calling app (Mother Admin only, enforced in the controller).
        Route::post('settings/calling-app/apk/uploads', [CallingAppApkUploadController::class, 'start'])->name('settings.calling-app.apk.start');
        Route::put('settings/calling-app/apk/uploads/{upload}/chunks/{index}', [CallingAppApkUploadController::class, 'chunk'])
            ->whereNumber('index')
            ->name('settings.calling-app.apk.chunk');
        Route::post('settings/calling-app/apk/uploads/{upload}/complete', [CallingAppApkUploadController::class, 'complete'])->name('settings.calling-app.apk.complete');

        // Monitoring — each page checks its own platform module permission
        // (App\Enums\MonitoringTool). The native Horizon/Pulse dashboards
        // live at admin/horizon and admin/pulse (config/horizon.php, config/pulse.php).
        Route::prefix('monitoring')->name('monitoring.')->group(function () {
            Route::livewire('horizon', MonitoringHorizon::class)->name('horizon');
            Route::livewire('horizon/failed-jobs', MonitoringFailedJobs::class)->name('horizon.failed-jobs');
            Route::livewire('health', MonitoringHealth::class)->name('health');
            Route::livewire('pulse', MonitoringPulse::class)->name('pulse');
            Route::livewire('integrations', MonitoringIntegrations::class)->name('integrations');
            Route::livewire('logs', MonitoringLogs::class)->name('logs');
            Route::get('logs/download/file/{id}', [LogDownloadController::class, 'file'])->name('logs.download-file');
            Route::get('logs/download/folder/{folder}', [LogDownloadController::class, 'folder'])->where('folder', '.*')->name('logs.download-folder');
        });
    });
});
