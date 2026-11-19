<?php

namespace App\Actions\Tenant;

use App\Models\TenantUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * WhatsApp calling companion app — mints the one-time code a logged-in user's
 * browser hands to the app (via the deep link returned here) so the app can
 * pair itself without a login screen. See PairCallingAppDevice for the
 * redeeming side.
 *
 * Only a SHA-256 hash of the code is ever stored (as the cache key), so a
 * cache dump can't be replayed into a pairing. The code lives for
 * config('calling.calling_app.pairing_code_ttl_seconds') and is consumed on
 * first redemption. Issuing a new code doesn't revoke an earlier one — both
 * are short-lived and each can pair only this same user.
 */
class IssueCallingAppPairingCode
{
    /**
     * @return array{code: string, deep_link: string, expires_in: int}
     */
    public function handle(TenantUser $user): array
    {
        $code = Str::random(48);
        $ttl = (int) config('calling.calling_app.pairing_code_ttl_seconds');

        Cache::put(self::cacheKey($code), $user->id, $ttl);

        return [
            'code' => $code,
            'deep_link' => config('calling.calling_app.deep_link_scheme').'://pair?code='.$code,
            'expires_in' => $ttl,
        ];
    }

    public static function cacheKey(string $code): string
    {
        return 'calling-app-pairing:'.hash('sha256', $code);
    }
}
