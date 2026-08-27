<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks tenant status on every request (not just at login) so a tenant
 * that gets suspended mid-session is immediately locked out.
 */
class EnsureTenantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantUser = Auth::guard('web')->user();

        if ($tenantUser) {
            $tenantUser->loadMissing('tenant');

            if (! $tenantUser->is_active || $tenantUser->tenant?->status !== TenantStatus::Active) {
                Auth::guard('web')->logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')
                    ->withErrors(['email' => 'Your account or organization has been deactivated.']);
            }
        }

        return $next($request);
    }
}
