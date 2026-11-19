<?php

use App\Http\Controllers\ActiveLeadCallsController;
use App\Http\Controllers\Api\LeadWebhookController;
use App\Http\Controllers\CallingAppController;
use App\Http\Controllers\LeadCallController;
use Illuminate\Support\Facades\Route;

Route::prefix('webhook')->name('api.webhook.')->group(function () {
    // F1/F8 remediation (forensic audit Batch A) — each platform now has TWO
    // POST routes: the legacy tenant_code-only shape (kept working
    // unmodified during the deprecation window — see
    // LeadWebhookController::resolveSource()'s own docblock) and a new
    // token-bearing shape carrying an opaque, per-source webhook_token
    // (mirrors WebsiteForm's own token_hash pattern exactly — see
    // 2026_09_29_070000_add_webhook_token_hash_to_lead_sources_table.php).
    // Both variants funnel into the SAME controller action; the action's
    // own optional $webhook_token parameter is simply absent (null) on the
    // legacy route. Rate limiting (SEC-2's ThrottleRequestsFailOpen, the
    // same fail-open middleware the legacy website-form routes already
    // use) runs BEFORE signature verification on every variant, so an
    // unauthenticated flood is rejected by a cheap counter check before
    // this app pays for the heavier HMAC/DB work — see
    // AppServiceProvider::configureRateLimiting()'s own docblock for the
    // limiter definitions.
    Route::post('facebook/{tenant_code}', [LeadWebhookController::class, 'facebookMessenger'])
        ->name('facebook')
        ->middleware(['throttle.fail-open:meta-webhook-facebook-per-source-ip', 'throttle.fail-open:meta-webhook-facebook-per-source', 'verify.meta.signature:facebook_messenger,facebook_lead_ads']);
    Route::post('facebook/{tenant_code}/{webhook_token}', [LeadWebhookController::class, 'facebookMessenger'])
        ->name('facebook.token')
        ->middleware(['throttle.fail-open:meta-webhook-facebook-per-source-ip', 'throttle.fail-open:meta-webhook-facebook-per-source', 'verify.meta.signature:facebook_messenger,facebook_lead_ads']);
    Route::get('facebook/{tenant_code}', [LeadWebhookController::class, 'verifyFacebook'])->name('facebook.verify');
    Route::get('facebook/{tenant_code}/{webhook_token}', [LeadWebhookController::class, 'verifyFacebook'])->name('facebook.verify.token');
    Route::post('whatsapp/{tenant_code}', [LeadWebhookController::class, 'whatsapp'])
        ->name('whatsapp')
        ->middleware(['throttle.fail-open:meta-webhook-whatsapp-per-source-ip', 'throttle.fail-open:meta-webhook-whatsapp-per-source', 'verify.meta.signature:whatsapp']);
    Route::post('whatsapp/{tenant_code}/{webhook_token}', [LeadWebhookController::class, 'whatsapp'])
        ->name('whatsapp.token')
        ->middleware(['throttle.fail-open:meta-webhook-whatsapp-per-source-ip', 'throttle.fail-open:meta-webhook-whatsapp-per-source', 'verify.meta.signature:whatsapp']);
    Route::get('whatsapp/{tenant_code}', [LeadWebhookController::class, 'verifyWhatsapp'])->name('whatsapp.verify');
    Route::get('whatsapp/{tenant_code}/{webhook_token}', [LeadWebhookController::class, 'verifyWhatsapp'])->name('whatsapp.verify.token');
    Route::post('instagram/{tenant_code}', [LeadWebhookController::class, 'instagram'])
        ->name('instagram')
        ->middleware(['throttle.fail-open:meta-webhook-instagram-per-source-ip', 'throttle.fail-open:meta-webhook-instagram-per-source', 'verify.meta.signature:instagram']);
    Route::post('instagram/{tenant_code}/{webhook_token}', [LeadWebhookController::class, 'instagram'])
        ->name('instagram.token')
        ->middleware(['throttle.fail-open:meta-webhook-instagram-per-source-ip', 'throttle.fail-open:meta-webhook-instagram-per-source', 'verify.meta.signature:instagram']);
    Route::get('instagram/{tenant_code}', [LeadWebhookController::class, 'verifyInstagram'])->name('instagram.verify');
    Route::get('instagram/{tenant_code}/{webhook_token}', [LeadWebhookController::class, 'verifyInstagram'])->name('instagram.verify.token');
    // SEC-2 — this route previously had no rate limiting at all (see
    // App\Providers\AppServiceProvider::configureRateLimiting()'s own
    // docblock for why these two limiters are keyed on LeadSource.id
    // rather than the form-token dimension the builder route below uses).
    // ThrottleRequestsFailOpen, not the stock `throttle:` middleware —
    // see that class's docblock.
    Route::post('website-form/{tenant_code}', [LeadWebhookController::class, 'websiteForm'])
        ->name('website-form')
        ->middleware(['throttle.fail-open:legacy-website-form-per-source-ip', 'throttle.fail-open:legacy-website-form-per-source']);
    // Feature #13 — a builder-managed form's own submission endpoint,
    // authenticated by its public token rather than the source-wide API
    // key above. Deliberately a separate route/method: the legacy route
    // above keeps its exact existing behavior, unthrottled — SEC-2 added
    // rate limiting to it directly above, still untouched here.
    Route::post('website-form/{tenant_code}/{form_token}', [LeadWebhookController::class, 'websiteFormSubmit'])
        ->name('website-form.submit')
        ->middleware(['throttle:website-form-per-form', 'throttle:website-form-per-tenant']);
});

// WhatsApp calling companion app (native Android/iOS app with earpiece
// support). No login screen: `pair` exchanges a one-time code minted by the
// user's logged-in browser (Settings → Calling app) for a Sanctum device
// token. Every other route authenticates with that token, then
// `calling-app.device` bridges the user onto the `web` guard so the same
// tenant/module middleware and the SAME call controllers/actions the
// browser softphone uses apply unchanged — nothing here re-implements a
// call action.
Route::prefix('calling-app')->name('api.calling-app.')->group(function () {
    Route::post('pair', [CallingAppController::class, 'pair'])
        ->middleware('throttle:calling-app-pair')
        ->name('pair');

    Route::middleware(['auth:sanctum', 'calling-app.device', 'tenant.identify', 'verified', 'module:lead-management'])->group(function () {
        Route::get('me', [CallingAppController::class, 'me'])->name('me');
        Route::post('heartbeat', [CallingAppController::class, 'heartbeat'])->name('heartbeat');
        Route::post('unpair', [CallingAppController::class, 'unpair'])->name('unpair');
        Route::post('push-token', [CallingAppController::class, 'pushToken'])->name('push-token');

        Route::get('calls/active', ActiveLeadCallsController::class)
            ->middleware('throttle.fail-open:lead-call-actions')
            ->name('calls.active');

        Route::middleware('throttle.fail-open:lead-call-actions')->prefix('calls/{leadCall}')->name('calls.')->group(function () {
            Route::post('claim', [LeadCallController::class, 'claim'])->name('claim');
            Route::post('answer', [LeadCallController::class, 'answer'])->name('answer');
            Route::post('accept', [LeadCallController::class, 'accept'])->name('accept');
            Route::post('reject', [LeadCallController::class, 'reject'])->name('reject');
            Route::post('hangup', [LeadCallController::class, 'hangup'])->name('hangup');
            Route::post('cancel-outbound', [LeadCallController::class, 'cancelOutbound'])->name('cancel-outbound');
            Route::get('outbound-answer', [LeadCallController::class, 'outboundAnswer'])->name('outbound-answer');
            Route::post('outbound-connected', [LeadCallController::class, 'outboundConnected'])->name('outbound-connected');
        });

        Route::post('leads/{lead}/calls/outbound', [LeadCallController::class, 'initiateOutbound'])
            ->middleware('throttle.fail-open:lead-call-actions')
            ->name('calls.initiate-outbound');
    });
});
