<?php

use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.identify', 'tenant.active'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('profile.edit');
});

Route::middleware(['auth', 'verified', 'tenant.identify', 'tenant.active'])->group(function () {
    Route::livewire('settings/security', Security::class)
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});
