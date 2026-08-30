<?php

use App\Http\Controllers\MetaPermissionsController;
use App\Livewire\Actions\PlatformLogout;
use App\Livewire\Platform\Admins\Index as AdminsIndex;
use App\Livewire\Platform\Auth\Login;
use App\Livewire\Platform\Clients\Index as ClientsIndex;
use App\Livewire\Platform\Clients\LeadSources;
use App\Livewire\Platform\Clients\Show as ClientsShow;
use App\Livewire\Platform\Dashboard;
use App\Livewire\Platform\Modules\Index as ModulesIndex;
use App\Livewire\Platform\Roles\Index as RolesIndex;
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
        Route::livewire('settings/password', SettingsPassword::class)->name('settings.password');
    });
});
