<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend gate for a specific ERP module. No module routes exist yet in
 * this foundation phase, but future modules attach this middleware
 * (`Route::middleware('module:sales-crm')`) instead of relying on the
 * sidebar hiding a link.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $moduleSlug): Response
    {
        $tenantUser = Auth::guard('web')->user();

        abort_unless(
            $tenantUser
                && $tenantUser->tenant?->hasModule($moduleSlug)
                && $tenantUser->role->hasModule($moduleSlug),
            403
        );

        return $next($request);
    }
}
