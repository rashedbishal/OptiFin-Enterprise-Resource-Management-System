<?php

use App\Http\Controllers\LeadAttachmentController;
use App\Livewire\Tenant\Dashboard;
use App\Livewire\Tenant\Leads\Index as LeadsIndex;
use App\Livewire\Tenant\Leads\Show as LeadsShow;
use App\Livewire\Tenant\LeadSources\Index as LeadSourcesIndex;
use App\Livewire\Tenant\ModulePlaceholder;
use App\Livewire\Tenant\Roles\Index as RolesIndex;
use App\Livewire\Tenant\Users\Index as UsersIndex;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'tenant.identify', 'tenant.active'])->group(function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('users', UsersIndex::class)->name('tenant-users.index');
    Route::livewire('roles', RolesIndex::class)->name('tenant-roles.index');
    Route::livewire('modules/{module:slug}', ModulePlaceholder::class)->name('modules.show');

    Route::middleware('module:lead-management')->group(function () {
        Route::livewire('lead-sources', LeadSourcesIndex::class)->name('tenant-lead-sources.index');
        Route::livewire('leads', LeadsIndex::class)->name('tenant-leads.index');
        Route::livewire('leads/{lead}', LeadsShow::class)->name('tenant-leads.show');
        Route::get('leads/{lead}/attachments/{attachment}', [LeadAttachmentController::class, 'download'])->name('tenant-leads.attachment.download');
    });
});

require __DIR__.'/settings.php';
