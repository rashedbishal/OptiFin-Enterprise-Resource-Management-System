<?php

use App\Http\Controllers\LeadAttachmentController;
use App\Http\Controllers\LeadCallController;
use App\Http\Controllers\LeadMessageAttachmentController;
use App\Http\Controllers\NotificationRedirectController;
use App\Livewire\Tenant\Dashboard;
use App\Livewire\Tenant\LeadCustomFields\Index as LeadCustomFieldsIndex;
use App\Livewire\Tenant\LeadImports\Index as LeadImportsIndex;
use App\Livewire\Tenant\LeadImports\Show as LeadImportsShow;
use App\Livewire\Tenant\LeadManagement\Dashboard as LeadManagementDashboard;
use App\Livewire\Tenant\LeadReports\Index as LeadReportsIndex;
use App\Livewire\Tenant\Leads\Index as LeadsIndex;
use App\Livewire\Tenant\Leads\Show as LeadsShow;
use App\Livewire\Tenant\Leads\SourceLeads as LeadsSourceLeads;
use App\Livewire\Tenant\LeadScoring\Index as LeadScoringIndex;
use App\Livewire\Tenant\LeadSourceCosts\Index as LeadSourceCostsIndex;
use App\Livewire\Tenant\LeadSources\Index as LeadSourcesIndex;
use App\Livewire\Tenant\LeadTags\Index as LeadTagsIndex;
use App\Livewire\Tenant\ModulePlaceholder;
use App\Livewire\Tenant\Roles\Index as RolesIndex;
use App\Livewire\Tenant\Users\Index as UsersIndex;
use App\Livewire\Tenant\WebsiteForms\Index as WebsiteFormsIndex;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified', 'tenant.identify', 'tenant.active'])->group(function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');
    Route::livewire('users', UsersIndex::class)->name('tenant-users.index');
    Route::livewire('roles', RolesIndex::class)->name('tenant-roles.index');
    Route::livewire('modules/{module:slug}', ModulePlaceholder::class)->name('modules.show');
    Route::get('notifications/{notification}/open', NotificationRedirectController::class)->name('tenant-notifications.open');

    Route::middleware('module:lead-management')->prefix('lead-management')->group(function () {
        // Module hub: dashboard linking into every nested page below.
        Route::livewire('/', LeadManagementDashboard::class)->name('lead-management.index');

        Route::livewire('leads', LeadsIndex::class)->name('tenant-leads.index');
        Route::livewire('lead-sources', LeadSourcesIndex::class)->name('tenant-lead-sources.index');
        Route::livewire('leads/sources/{leadSource}', LeadsSourceLeads::class)->name('tenant-leads.source');
        Route::livewire('leads/scoring-rules', LeadScoringIndex::class)->name('tenant-leads.scoring-rules');
        Route::livewire('leads/tags', LeadTagsIndex::class)->name('tenant-leads.tags');
        Route::livewire('leads/custom-fields', LeadCustomFieldsIndex::class)->name('tenant-leads.custom-fields');
        Route::livewire('leads/source-costs', LeadSourceCostsIndex::class)->name('tenant-leads.source-costs');
        Route::livewire('leads/website-forms', WebsiteFormsIndex::class)->name('tenant-leads.website-forms');
        Route::livewire('leads/reports', LeadReportsIndex::class)->name('tenant-leads.reports');
        Route::livewire('leads/import', LeadImportsIndex::class)->name('tenant-lead-imports.index');
        Route::livewire('leads/import/{leadImport}', LeadImportsShow::class)->name('tenant-lead-imports.show');
        // WhatsApp inbound calling — JSON actions on a live call. The call id is
        // the only browser-supplied identifier; the actions resolve it inside
        // the authenticated tenant. Fail-open throttle: see the limiter's note.
        Route::middleware('throttle.fail-open:lead-call-actions')->prefix('calls/{leadCall}')->group(function () {
            Route::post('claim', [LeadCallController::class, 'claim'])->name('tenant-lead-calls.claim');
            Route::post('answer', [LeadCallController::class, 'answer'])->name('tenant-lead-calls.answer');
            Route::post('accept', [LeadCallController::class, 'accept'])->name('tenant-lead-calls.accept');
            Route::post('reject', [LeadCallController::class, 'reject'])->name('tenant-lead-calls.reject');
        });

        Route::livewire('leads/{lead}', LeadsShow::class)->name('tenant-leads.show');
        Route::get('leads/{lead}/attachments/{attachment}', [LeadAttachmentController::class, 'download'])->name('tenant-leads.attachment.download');
        Route::get('leads/{lead}/messages/{message}/attachment', [LeadMessageAttachmentController::class, 'show'])->name('tenant-leads.message-attachment');
    });
});

require __DIR__.'/settings.php';
