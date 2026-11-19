<?php

namespace App\Actions\Tenant;

use App\Models\CallingAppDevice;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp calling companion app — redeems a one-time pairing code (see
 * IssueCallingAppPairingCode) for a long-lived Sanctum device token.
 *
 * The code is pulled under a short cache lock so two concurrent redemptions
 * of the same code can never both succeed. One phone per user (product
 * decision): any previously paired device is unpaired — its token deleted —
 * in the same transaction that creates the new one, so the old phone stops
 * working the moment the new one pairs.
 *
 * The same "account usable" rule the calling-app API enforces on every
 * request (CallingAppDevice::userMayUse()) is checked here too, so a code
 * issued just before a deactivation can't still mint a token.
 */
class PairCallingAppDevice
{
    public function __construct(private UnpairCallingAppDevice $unpair) {}

    /**
     * @return array{token: string, device: CallingAppDevice}|null null when the code is unknown, expired, already used, or the account is no longer usable
     */
    public function handle(string $code, ?string $deviceName): ?array
    {
        $cacheKey = IssueCallingAppPairingCode::cacheKey($code);

        $userId = Cache::lock($cacheKey.':lock', 5)->block(3, fn (): mixed => Cache::pull($cacheKey));

        if (! is_string($userId)) {
            return null;
        }

        $user = TenantUser::query()->with('tenant')->find($userId);

        if ($user === null || ! CallingAppDevice::userMayUse($user)) {
            return null;
        }

        return DB::transaction(function () use ($user, $deviceName): array {
            $existing = CallingAppDevice::query()->where('tenant_user_id', $user->id)->first();

            if ($existing !== null) {
                $this->unpair->handle($existing);
            }

            $newToken = $user->createToken('Calling app', [CallingAppDevice::TOKEN_ABILITY]);

            $device = CallingAppDevice::query()->create([
                'tenant_id' => $user->tenant_id,
                'tenant_user_id' => $user->id,
                'personal_access_token_id' => $newToken->accessToken->getKey(),
                'device_name' => $deviceName !== null ? mb_substr($deviceName, 0, 100) : null,
                'last_seen_at' => now(),
            ]);

            return ['token' => $newToken->plainTextToken, 'device' => $device];
        });
    }
}
