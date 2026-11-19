<?php

namespace App\Actions\Tenant;

use App\Models\CallingAppDevice;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * WhatsApp calling companion app — unpairs a phone: deletes the device row
 * and its Sanctum token, so the app's very next request is rejected.
 * Authorization is the caller's job (the user themselves from Settings, or
 * a Client Admin from the Users page) — this is the shared primitive.
 */
class UnpairCallingAppDevice
{
    public function handle(CallingAppDevice $device): void
    {
        $tokenId = $device->personal_access_token_id;

        $device->delete();

        PersonalAccessToken::query()->whereKey($tokenId)->delete();
    }
}
