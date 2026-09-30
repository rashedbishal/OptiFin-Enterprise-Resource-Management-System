<?php

namespace App\Services;

use App\Models\TenantUser;
use Illuminate\Support\Carbon;

/**
 * WhatsApp Call Backup / Overflow Receiver — whether a TenantUser's softphone
 * tab is considered live right now, so App\Services\CallRouter can tell a
 * genuinely-present assignee from one who simply isn't at their softphone
 * (there is otherwise no online/offline signal anywhere in this app — see
 * CallRouter's own docblock). Not a general presence system: the only writer
 * is App\Actions\Tenant\TouchSoftphonePresence, and the only reader is
 * CallRouter, for the single assignee it is already deciding about.
 *
 * softphone_last_seen_at is refreshed as a side effect of the softphone's own
 * existing GET calls/active poll (`calling.softphone_heartbeat_seconds`,
 * currently 15s in the healthy state, as fast as `calling.softphone_poll_
 * seconds` once degraded) — so a tab that is open, visible and online touches
 * it at least that often. `calling.softphone_presence_stale_seconds`
 * therefore has to tolerate a few missed cycles (backgrounded tab throttling,
 * a slow request, a brief network blip) without misreading a present user as
 * gone; see that config key's own docblock for the exact multiple chosen.
 */
class SoftphonePresence
{
    /**
     * NULL — never seen a heartbeat, whether a pre-existing row from before
     * this column existed or a user who has simply never opened the
     * softphone — is deliberately treated as available. Only a timestamp
     * that has gone stale (the user WAS seen, and then wasn't for long
     * enough) downgrades them; this is what keeps every pre-existing
     * assignment routing exactly as before the instant this ships, and never
     * penalizes a user for a capability they haven't used yet.
     */
    public function isAvailable(TenantUser $user): bool
    {
        $lastSeen = $user->softphone_last_seen_at;

        if ($lastSeen === null) {
            return true;
        }

        $staleSeconds = (int) config('calling.softphone_presence_stale_seconds');

        return $lastSeen->greaterThan(Carbon::now()->subSeconds($staleSeconds));
    }
}
