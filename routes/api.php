<?php

use App\Http\Controllers\Api\LeadWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('webhook')->name('api.webhook.')->group(function () {
    Route::post('facebook/{tenant_code}', [LeadWebhookController::class, 'facebookMessenger'])->name('facebook');
    Route::get('facebook/{tenant_code}', [LeadWebhookController::class, 'verifyFacebook'])->name('facebook.verify');
    Route::post('whatsapp/{tenant_code}', [LeadWebhookController::class, 'whatsapp'])->name('whatsapp');
    Route::get('whatsapp/{tenant_code}', [LeadWebhookController::class, 'verifyWhatsapp'])->name('whatsapp.verify');
    Route::post('website-form/{tenant_code}', [LeadWebhookController::class, 'websiteForm'])->name('website-form');
});
