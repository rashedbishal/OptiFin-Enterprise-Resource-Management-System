<?php

use App\Http\Controllers\ActiveLeadCallsController;
use App\Http\Controllers\LeadAttachmentController;
use App\Http\Controllers\LeadCallController;
use App\Http\Controllers\LeadMessageAttachmentController;
use App\Http\Controllers\NotificationRedirectController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\WhatsAppCallRingtoneController;
use App\Livewire\Tenant\CallingSettings\Index as CallingSettingsIndex;
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
use App\Livewire\Tenant\MessengerTemplates\Index as MessengerTemplatesIndex;
use App\Livewire\Tenant\ModulePlaceholder;
use App\Livewire\Tenant\Roles\Index as RolesIndex;
use App\Livewire\Tenant\Users\Index as UsersIndex;
use App\Livewire\Tenant\WebsiteForms\Index as WebsiteFormsIndex;
use App\Livewire\Tenant\WhatsAppTemplates\Index as WhatsAppTemplatesIndex;
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
        Route::livewire('leads/calling-settings', CallingSettingsIndex::class)->name('tenant-leads.calling-settings');
        Route::livewire('leads/whatsapp-templates', WhatsAppTemplatesIndex::class)->name('tenant-leads.whatsapp-templates');
        Route::livewire('leads/messenger-templates', MessengerTemplatesIndex::class)->name('tenant-leads.messenger-templates');
        // WhatsApp inbound calling — streams the tenant's custom ringtone
        // (if set) to the global softphone shell, which lives in the main
        // layout regardless of which lead-management page is open; gating
        // it here (rather than the outer, non-module group) still matches
        // reality since the softphone itself only ever renders when the
        // lead-management module is active (see components/softphone.blade.php).
        Route::get('whatsapp-call-ringtone', [WhatsAppCallRingtoneController::class, 'show'])->name('tenant.whatsapp-call-ringtone');
        // WhatsApp inbound calling — registers/unregisters this browser's Web
        // Push subscription so an incoming call can wake the OS even with the
        // tab closed. Lives alongside the softphone's own routes since it's
        // only ever called from there.
        Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('tenant.push-subscriptions.store');
        Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('tenant.push-subscriptions.destroy');
        Route::livewire('leads/import', LeadImportsIndex::class)->name('tenant-lead-imports.index');
        Route::livewire('leads/import/{leadImport}', LeadImportsShow::class)->name('tenant-lead-imports.show');
        // WhatsApp inbound calling — JSON actions on a live call. The call id is
        // the only browser-supplied identifier; the actions resolve it inside
        // the authenticated tenant. Fail-open throttle: see the limiter's note.
        Route::get('calls/active', ActiveLeadCallsController::class)
            ->middleware('throttle.fail-open:lead-call-actions')
            ->name('tenant-lead-calls.active');

        Route::middleware('throttle.fail-open:lead-call-actions')->prefix('calls/{leadCall}')->group(function () {
            Route::post('claim', [LeadCallController::class, 'claim'])->name('tenant-lead-calls.claim');
            Route::post('answer', [LeadCallController::class, 'answer'])->name('tenant-lead-calls.answer');
            Route::post('accept', [LeadCallController::class, 'accept'])->name('tenant-lead-calls.accept');
            Route::post('reject', [LeadCallController::class, 'reject'])->name('tenant-lead-calls.reject');
            Route::post('hangup', [LeadCallController::class, 'hangup'])->name('tenant-lead-calls.hangup');
            // WhatsApp Outbound Calling — additive.
            Route::post('cancel-outbound', [LeadCallController::class, 'cancelOutbound'])->name('tenant-lead-calls.cancel-outbound');
            Route::get('outbound-answer', [LeadCallController::class, 'outboundAnswer'])->name('tenant-lead-calls.outbound-answer');
            Route::post('outbound-connected', [LeadCallController::class, 'outboundConnected'])->name('tenant-lead-calls.outbound-connected');
        });

        Route::livewire('leads/{lead}', LeadsShow::class)->name('tenant-leads.show');
        Route::get('leads/{lead}/attachments/{attachment}', [LeadAttachmentController::class, 'download'])->name('tenant-leads.attachment.download');
        Route::get('leads/{lead}/messages/{message}/attachment', [LeadMessageAttachmentController::class, 'show'])->name('tenant-leads.message-attachment');
        // WhatsApp Outbound Calling — an agent places a call to this lead.
        Route::post('leads/{lead}/calls/outbound', [LeadCallController::class, 'initiateOutbound'])
            ->middleware('throttle.fail-open:lead-call-actions')
            ->name('tenant-lead-calls.initiate-outbound');
    });
});

require __DIR__.'/settings.php';
