<?php

namespace App\Jobs;

use App\Enums\CallStatus;
use App\Enums\TenantStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\IncomingWhatsAppCallNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * WhatsApp inbound calling — pushes every routed recipient's subscribed
 * devices the instant a call starts Ringing. Mirrors
 * App\Jobs\SendMissedCallNotification's own shape exactly (re-read the call
 * fresh inside the tenant, re-check it's still the state that justified
 * sending, resolve recipients now rather than trust transition-time data),
 * queued so a webhook never waits on it.
 *
 * Recipients are the call's own frozen routing snapshot (routed_user_ids —
 * assignee, backup receivers, or admin fallback, whichever CallRouter
 * decided), not "every Client Admin" like the missed-call bell: a push is
 * only useful to someone who could actually answer this specific call.
 * ->notify() is a safe no-op for a recipient with zero push subscriptions
 * (NotificationChannels\WebPush just sends to an empty list), so this never
 * needs to check who has one first.
 */
class SendIncomingCallPushNotification implements ShouldQueue
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

        if ($call === null || $call->status !== CallStatus::Ringing) {
            return;
        }

        $tenantIsActive = Tenant::query()
            ->whereKey($this->tenantId)
            ->where('status', TenantStatus::Active)
            ->exists();

        if (! $tenantIsActive) {
            return;
        }

        $lead = Lead::query()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($call->lead_id)
            ->first();

        if ($lead === null) {
            return;
        }

        $recipientIds = array_values(array_filter($call->routed_user_ids ?? [], 'is_string'));

        if ($recipientIds === []) {
            return;
        }

        $notification = new IncomingWhatsAppCallNotification($call->id, $lead->id, $lead->name, $lead->phone);

        TenantUser::query()
            ->where('tenant_id', $this->tenantId)
            ->whereIn('id', $recipientIds)
            ->where('is_active', true)
            ->get()
            ->each(fn (TenantUser $recipient) => $recipient->notify($notification));
    }
}
