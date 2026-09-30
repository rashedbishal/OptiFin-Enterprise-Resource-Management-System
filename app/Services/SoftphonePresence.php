<?php

namespace App\Services;

use App\Enums\ReverbPresenceResult;
use App\Models\TenantUser;
use Illuminate\Support\Carbon;

/**
 * WhatsApp Call Backup / Overflow Receiver — whether a TenantUser's softphone
 * is considered reachable right now, so App\Services\CallRouter can tell a
 * genuinely-present assignee from one who simply isn't (there is otherwise no
 * general online/offline signal anywhere in this app — see CallRouter's own
 * docblock). This is the single abstraction CallRouter calls; it never talks
 * to Reverb or reads softphone_last_seen_at directly.
 *
 * Two-tier decision, in this order:
 *  1. Ask App\Services\ReverbPresenceQuery whether the assignee is a live
 *     member of their tenant's softphone presence channel — the actual
 *     product requirement ("is the realtime connection present"), not an
 *     approximation. When Reverb answers (Present/Absent), that answer wins
 *     outright.
 *  2. Only when the Reverb query itself fails (disabled, unreachable,
 *     timed out, malformed response — ReverbPresenceResult::QueryFailed),
 *     fall back to the pre-existing softphone_last_seen_at heartbeat check
 *     unchanged. A Reverb outage must never be read as "everyone is
 *     offline" — see ReverbPresenceQuery's own docblock — so QueryFailed
 *     deliberately does NOT mean unavailable here either; it means "decide
 *     the old way."
 *
 * softphone_last_seen_at (the fallback) is refreshed as a side effect of the
 * softphone's own existing GET calls/active poll (`calling.
 * softphone_heartbeat_seconds`, currently 15s in the healthy state, as fast
 * as `calling.softphone_poll_seconds` once degraded) — so a tab that is
 * open, visible and online touches it at least that often. `calling.
 * softphone_presence_stale_seconds` therefore has to tolerate a few missed
 * cycles (backgrounded tab throttling, a slow request, a brief network blip)
 * without misreading a present user as gone; see that config key's own
 * docblock for the exact multiple chosen.
 */
class SoftphonePresence
{
    public function __construct(private readonly ReverbPresenceQuery $reverb) {}

    public function isAvailable(TenantUser $user): bool
    {
        return match ($this->reverb->check($user)) {
            ReverbPresenceResult::Present => true,
            ReverbPresenceResult::Absent => false,
            ReverbPresenceResult::QueryFailed => $this->isAvailableByTimestamp($user),
        };
    }

    /**
     * NULL — never seen a heartbeat, whether a pre-existing row from before
     * this column existed or a user who has simply never opened the
     * softphone — is deliberately treated as available. Only a timestamp
     * that has gone stale (the user WAS seen, and then wasn't for long
     * enough) downgrades them; this is what keeps every pre-existing
     * assignment routing exactly as before the instant this ships, and never
     * penalizes a user for a capability they haven't used yet.
     */
    private function isAvailableByTimestamp(TenantUser $user): bool
    {
        $lastSeen = $user->softphone_last_seen_at;

        if ($lastSeen === null) {
            return true;
        }

        $staleSeconds = (int) config('calling.softphone_presence_stale_seconds');

        return $lastSeen->greaterThan(Carbon::now()->subSeconds($staleSeconds));
    }
}
