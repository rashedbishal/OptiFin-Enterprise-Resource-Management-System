<?php

use App\Http\Controllers\Api\LeadWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('webhook')->name('api.webhook.')->group(function () {
    Route::post('facebook/{tenant_code}', [LeadWebhookController::class, 'facebookMessenger'])->name('facebook')->middleware('verify.meta.signature:facebook_messenger');
    Route::get('facebook/{tenant_code}', [LeadWebhookController::class, 'verifyFacebook'])->name('facebook.verify');
    Route::post('whatsapp/{tenant_code}', [LeadWebhookController::class, 'whatsapp'])->name('whatsapp')->middleware('verify.meta.signature:whatsapp');
    Route::get('whatsapp/{tenant_code}', [LeadWebhookController::class, 'verifyWhatsapp'])->name('whatsapp.verify');
    Route::post('instagram/{tenant_code}', [LeadWebhookController::class, 'instagram'])->name('instagram')->middleware('verify.meta.signature:instagram');
    Route::get('instagram/{tenant_code}', [LeadWebhookController::class, 'verifyInstagram'])->name('instagram.verify');
    Route::post('website-form/{tenant_code}', [LeadWebhookController::class, 'websiteForm'])->name('website-form');
});
