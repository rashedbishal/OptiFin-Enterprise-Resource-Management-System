<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finer-grained than EnsureModuleAccess: also requires a specific
 * permission (view/create/update/delete) on the module. Built now as the
 * seam future module routes plug into.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $moduleSlug, string $permissionSlug): Response
    {
        $user = Auth::guard('web')->user() ?? Auth::guard('platform')->user();

        abort_unless(
            $user && $user->hasModulePermission($moduleSlug, $permissionSlug),
            403
        );

        return $next($request);
    }
}
