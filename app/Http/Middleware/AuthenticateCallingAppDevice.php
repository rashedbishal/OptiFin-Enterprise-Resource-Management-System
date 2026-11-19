<?php

namespace App\Http\Middleware;

use App\Models\CallingAppDevice;
use App\Models\TenantUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * WhatsApp calling companion app — runs right after `auth:sanctum` on every
 * /api/calling-app route except pairing itself, and bridges the app's
 * bearer-token request onto the browser-side calling stack unchanged:
 *
 * 1. The token must carry CallingAppDevice::TOKEN_ABILITY and still belong
 *    to a paired device (unpairing deletes both, so this mostly guards
 *    against any future non-calling-app token reaching these routes).
 * 2. The account must still be usable (CallingAppDevice::userMayUse()) —
 *    EnsureTenantActive's own rule, answered as JSON here since that
 *    middleware needs a session to log out of and redirects to /login.
 * 3. The user is set on the `web` guard for this request only (setUser()
 *    never touches a session) so the existing `tenant.identify` and
 *    `module:*` middleware, which read that guard explicitly, apply exactly
 *    as they do to the browser — no parallel copies of those checks.
 */
class AuthenticateCallingAppDevice
{
    /**
     * How stale last_seen_at may get before a request refreshes it — keeps
     * the frequent active-calls poll from writing on every hit.
     */
    private const LAST_SEEN_REFRESH_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof TenantUser ? $user->currentAccessToken() : null;

        if (! $user instanceof TenantUser || ! $token instanceof PersonalAccessToken || ! $token->can(CallingAppDevice::TOKEN_ABILITY)) {
            return $this->error(401, 'unpaired', __('This device is not paired.'));
        }

        $device = CallingAppDevice::query()
            ->where('personal_access_token_id', $token->getKey())
            ->where('tenant_user_id', $user->id)
            ->first();

        if ($device === null) {
            return $this->error(401, 'unpaired', __('This device is not paired.'));
        }

        if (! CallingAppDevice::userMayUse($user)) {
            return $this->error(403, 'account_inactive', __('Your account or organization has been deactivated.'));
        }

        Auth::guard('web')->setUser($user);

        $request->attributes->set('callingAppDevice', $device);

        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subSeconds(self::LAST_SEEN_REFRESH_SECONDS))) {
            $device->forceFill(['last_seen_at' => now()])->save();
        }

        return $next($request);
    }

    private function error(int $status, string $code, string $message): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'retryable' => false]], $status);
    }
}
