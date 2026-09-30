<?php

namespace App\Services;

use App\Contracts\ReverbChannelUsersGateway;
use App\Enums\ReverbPresenceResult;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp Call Backup / Overflow Receiver, Phase 2 — asks Reverb, via its
 * Pusher-compatible presence-channel "users" endpoint, whether a specific
 * TenantUser's softphone is currently connected. Read-only, single-purpose:
 * this is the only thing App\Services\SoftphonePresence asks it for, and the
 * only channel it ever queries is the one TENANT-scoped softphone presence
 * channel registered in routes/channels.php as `tenant-softphone.{tenantId}`
 * (wire name `presence-tenant-softphone.{tenantId}`, per
 * Illuminate\Broadcasting\PresenceChannel's own "presence-" prefixing).
 *
 * Multi-tab/multi-device: Reverb's own channel-users metric already
 * deduplicates connections by their presence "user_id" (see
 * Laravel\Reverb\Protocols\Pusher\MetricsHandler::channelUsers(),
 * ->unique('user_id')) — the id Laravel's PusherBroadcaster derives from
 * $user->getAuthIdentifier(), i.e. TenantUser::id (see routes/channels.php's
 * auth callback). Two tabs of the same user therefore collapse to exactly
 * one entry; closing one tab while the other stays open never flips this to
 * Absent. This class does no reference counting of its own — none is needed.
 *
 * Disabled by default (`calling.reverb_presence_enabled`, false) so a
 * backend deploy of this class changes nothing until the frontend is
 * confirmed joining the presence channel — see the config key's own
 * docblock for the full partial-rollout reasoning.
 */
class ReverbPresenceQuery
{
    private const int LOG_THROTTLE_SECONDS = 60;

    public function __construct(private readonly ReverbChannelUsersGateway $gateway) {}

    public function check(TenantUser $user): ReverbPresenceResult
    {
        if (! config('calling.reverb_presence_enabled')) {
            return ReverbPresenceResult::QueryFailed;
        }

        try {
            $memberIds = $this->gateway->memberIds($this->channelName($user->tenant_id));
        } catch (Throwable $exception) {
            $this->logFailure($exception, $user);

            return ReverbPresenceResult::QueryFailed;
        }

        return in_array($user->id, $memberIds, true)
            ? ReverbPresenceResult::Present
            : ReverbPresenceResult::Absent;
    }

    private function channelName(string $tenantId): string
    {
        return 'presence-tenant-softphone.'.$tenantId;
    }

    /**
     * Never blocks or fails the caller — a Reverb outage must stay silent to
     * the webhook it's routing for (see SoftphonePresence/CallRouter). IDs
     * only, no SDP/credential/phone data was ever in scope here to leak.
     * Throttled to at most once per LOG_THROTTLE_SECONDS application-wide, so
     * a sustained Reverb outage (one query per inbound call) can't flood the
     * log — the first failure in a window is enough to alert on.
     */
    private function logFailure(Throwable $exception, TenantUser $user): void
    {
        if (! Cache::add('reverb-presence-query-log-throttle', true, self::LOG_THROTTLE_SECONDS)) {
            return;
        }

        Log::channel('whatsapp')->warning('WA REVERB PRESENCE QUERY FAILED', [
            'tenant_id' => $user->tenant_id,
            'tenant_user_id' => $user->id,
            'exception' => $exception::class,
        ]);
    }
}
