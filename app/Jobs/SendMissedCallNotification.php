<?php

namespace App\Jobs;

use App\Enums\CallStatus;
use App\Enums\TenantStatus;
use App\Enums\TenantUserType;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\MissedCallNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * WhatsApp inbound calling — bells every active Client Admin of the call's
 * tenant that a call ended Missed. Queued so a webhook or the stale-call sweep
 * never waits on it.
 *
 * It trusts nothing but the call's identity: the call is re-read inside its own
 * tenant and must still be Missed, and the lead id and name come from that
 * fresh row (a merge between the transition and this job re-points the call at
 * the surviving lead). Recipients are resolved now, not at transition time.
 * A recipient who already holds a notification for this call is skipped, so a
 * retry after a partial run only sends what is missing.
 */
class SendMissedCallNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 60];

    public function __construct(
        public readonly string $tenantId,
        public readonly string $callId,
    ) {}

    public function handle(): void
    {
        $call = LeadCall::query()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->callId)
            ->first();

        if ($call === null || $call->status !== CallStatus::Missed) {
            return;
        }

        $lead = Lead::query()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($call->lead_id)
            ->first();

        $tenantIsActive = Tenant::query()
            ->whereKey($this->tenantId)
            ->where('status', TenantStatus::Active)
            ->exists();

        if ($lead === null || ! $tenantIsActive) {
            return;
        }

        TenantUser::query()
            ->where('tenant_id', $this->tenantId)
            ->where('user_type', TenantUserType::ClientAdmin)
            ->where('is_active', true)
            ->get()
            ->each(function (TenantUser $admin) use ($call, $lead): void {
                if ($this->alreadyNotified($admin, $call)) {
                    return;
                }

                $admin->notify(new MissedCallNotification($call->id, $lead->id, $lead->name));
            });
    }

    private function alreadyNotified(TenantUser $admin, LeadCall $call): bool
    {
        return $admin->notifications()
            ->where('type', MissedCallNotification::class)
            ->where('data', 'like', '%"call_id":"'.$call->id.'"%')
            ->exists();
    }
}
