<?php

namespace App\Jobs;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\TenantStatus;
use App\Models\CallingAppDevice;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\Tenant;
use App\Notifications\CallingAppCallPush;
use App\Services\CallingAppFirebase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WhatsApp calling companion app — pushes an inbound call to the paired
 * phones of the users CallRouter routed it to (the frozen routed_user_ids,
 * exactly like SendIncomingCallPushNotification's Web Push), alongside —
 * never instead of — the browser's realtime broadcast and Web Push.
 *
 *   ring  → `incoming_call`: only while the call is STILL Ringing when the
 *           job runs (a queue delay past the ring window sends nothing).
 *   ended → `call_ended`: the call left Ringing (answered elsewhere,
 *           declined, missed, hung up), so every other routed phone stops
 *           ringing. The claimant's own phone is skipped — it is the one
 *           taking the call.
 *
 * Only when the Firebase key is uploaded (Admin → Calling app), only active
 * users of an active tenant, only devices that have reported an FCM token.
 * A push that fails (stale token, Firebase outage) is reported by the FCM
 * channel's NotificationFailed event and never retried into a ring that has
 * already stopped.
 */
class SendCallingAppCallPush implements ShouldQueue
{
    use Queueable;

    public const RING = 'ring';

    public const ENDED = 'ended';

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $callId,
        public readonly string $kind,
    ) {}

    public function handle(CallingAppFirebase $firebase): void
    {
        // No key uploaded in Admin → Calling app: nothing to send with (and a
        // paired app doesn't count as available then, so nobody relies on it).
        if (! $firebase->isConfigured()) {
            return;
        }

        $call = LeadCall::query()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->callId)
            ->first();

        if ($call === null || $call->direction !== CallDirection::Inbound) {
            return;
        }

        $tenantIsActive = Tenant::query()->whereKey($this->tenantId)->where('status', TenantStatus::Active)->exists();

        if (! $tenantIsActive) {
            return;
        }

        if ($this->kind === self::RING) {
            $this->ring($call);
        } elseif ($this->kind === self::ENDED) {
            $this->ended($call);
        }
    }

    private function ring(LeadCall $call): void
    {
        $ringSecondsLeft = $call->ringing_expires_at === null ? 0 : (int) Carbon::now()->diffInSeconds($call->ringing_expires_at, false);

        if ($call->status !== CallStatus::Ringing || $ringSecondsLeft <= 0) {
            return;
        }

        $lead = Lead::query()->where('tenant_id', $this->tenantId)->whereKey($call->lead_id)->first();

        if ($lead === null) {
            return;
        }

        $push = CallingAppCallPush::incomingCall($call->id, $lead->name, $lead->phone, $ringSecondsLeft);

        $this->devices($call)->each(fn (CallingAppDevice $device) => $device->notify($push));
    }

    private function ended(LeadCall $call): void
    {
        if ($call->status === CallStatus::Ringing) {
            return;
        }

        $push = CallingAppCallPush::callEnded($call->id);

        $this->devices($call)
            ->reject(fn (CallingAppDevice $device) => $device->tenant_user_id === $call->claimed_by)
            ->each(fn (CallingAppDevice $device) => $device->notify($push));
    }

    /**
     * @return Collection<int, CallingAppDevice>
     */
    private function devices(LeadCall $call): Collection
    {
        $recipientIds = array_values(array_filter($call->routed_user_ids ?? [], 'is_string'));

        if ($recipientIds === []) {
            return new Collection;
        }

        return CallingAppDevice::query()
            ->where('tenant_id', $this->tenantId)
            ->whereIn('tenant_user_id', $recipientIds)
            ->whereNotNull('fcm_token')
            ->whereHas('tenantUser', fn ($query) => $query->where('is_active', true))
            ->get();
    }
}
