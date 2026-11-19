<?php

use App\Http\Controllers\CallingAppDownloadController;
use App\Livewire\Settings\CallingApp;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.identify', 'tenant.active'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('profile.edit');

    // Pair/unpair this user's phone with the WhatsApp calling companion
    // app — only meaningful where WhatsApp calling can exist at all.
    Route::livewire('settings/calling-app', CallingApp::class)
        ->middleware('module:lead-management')
        ->name('calling-app.edit');

    // Download the OptiFin Calling app APK (eligibility checked in the controller).
    Route::get('settings/calling-app/download', CallingAppDownloadController::class)
        ->middleware('module:lead-management')
        ->name('calling-app.download');
});

Route::middleware(['auth', 'verified', 'tenant.identify', 'tenant.active'])->group(function () {
    Route::livewire('settings/security', Security::class)
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});
