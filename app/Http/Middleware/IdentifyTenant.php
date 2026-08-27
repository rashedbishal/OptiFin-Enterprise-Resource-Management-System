<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the current tenant into the container so `BelongsToTenant`-using
 * models can scope themselves automatically, and shares it with views for
 * the tenant sidebar/dashboard.
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantUser = Auth::guard('web')->user();

        if ($tenantUser) {
            $tenantUser->loadMissing('tenant');

            app()->instance('currentTenant', $tenantUser->tenant);

            view()->share('currentTenant', $tenantUser->tenant);
        }

        return $next($request);
    }
}
