<?php

use App\Http\Middleware\AuthenticateCallingAppDevice;
use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\ThrottleRequestsFailOpen;
use App\Http\Middleware\VerifyMetaWebhookSignature;
use App\Services\ErrorAlertNotifier;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(__DIR__.'/../routes/admin.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.active' => EnsureTenantActive::class,
            'tenant.identify' => IdentifyTenant::class,
            'module' => EnsureModuleAccess::class,
            'permission' => EnsurePermission::class,
            'verify.meta.signature' => VerifyMetaWebhookSignature::class,
            'throttle.fail-open' => ThrottleRequestsFailOpen::class,
            'calling-app.device' => AuthenticateCallingAppDevice::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin/*') ? route('admin.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('admin/*') ? route('admin.dashboard') : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // "New kind of error" emails (Monitoring alerts). Runs alongside the
        // normal log entry, never instead of it, and never throws.
        $exceptions->reportable(function (Throwable $e): void {
            app(ErrorAlertNotifier::class)->handle($e);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
