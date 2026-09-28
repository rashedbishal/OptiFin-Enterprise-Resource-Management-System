<?php

namespace App\Actions\Tenant;

use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallRoutingMode;
use App\Enums\CallStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Services\WhatsAppCallingClient;
use App\Support\LeadCallActionResult;
use App\Support\LeadCallBroadcaster;
use App\Support\LeadCallSdp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WhatsApp Outbound Calling — an agent places a call to a Lead.
 *
 * Order (per the approved architecture): authorize the Lead → resolve its
 * WhatsApp source server-side → validate the browser's SDP offer → a LIVE
 * call_permissions check (never held under the lock below — Meta remains
 * authoritative right up to the moment of connect, never trusted from
 * locally-cached state) → only then, under a short per-Lead lock, confirm no
 * other outbound call is already in flight and create the Initiated row →
 * release the lock → call Meta connect exactly once, outside any lock or
 * transaction → a single conditional UPDATE applies the result (the wacid
 * and Ringing on success, Failed on failure).
 *
 * `routed_user_ids = [$user->id]` and `claimed_by = $user->id` at creation:
 * this is the one field that lets every existing, UNCHANGED piece of
 * inbound-calling infrastructure (LeadCallPolicy::answer(), HangupLeadCall,
 * ListActiveLeadCalls, the realtime broadcaster) authorize and surface an
 * outbound call correctly with zero code of their own needing to know
 * direction exists.
 */
class InitiateOutboundCall
{
    public function __construct(
        private readonly CheckLeadCallPermission $checkPermission,
        private readonly ResolveLeadWhatsAppSource $resolveSource,
    ) {}

    public function handle(TenantUser $user, Lead $lead, string $sdpOffer): LeadCallActionResult
    {
        Gate::forUser($user)->authorize('update', $lead);

        $source = $this->resolveSource->handle($lead);

        if ($source === null) {
            return LeadCallActionResult::error(422, 'no_whatsapp_source', __('This lead has no WhatsApp number to call.'));
        }

        if (! $source->is_active || ! $source->calling_enabled) {
            return LeadCallActionResult::error(409, 'calling_unavailable', __('Calling is not available for this WhatsApp number.'));
        }

        $offer = LeadCallSdp::normalizeOffer($sdpOffer);

        if ($offer === null) {
            return LeadCallActionResult::error(422, 'invalid_sdp', __('The offer SDP is missing or not valid.'));
        }

        $phone = (string) $lead->phone;
        $permission = $this->checkPermission->handle($lead, $source);

        if (! $permission->successful || ! $this->canStartCall($permission->data['actions'] ?? [])) {
            return LeadCallActionResult::error(409, 'permission_required', __('This WhatsApp user has not granted permission for calls from this business.'));
        }

        $call = $this->createInitiated($user, $lead, $source);

        if ($call === null) {
            return LeadCallActionResult::error(409, 'call_already_in_progress', __('A call to this lead is already in progress.'));
        }

        $api = (new WhatsAppCallingClient($source))->connect($phone, $offer, $call->biz_opaque_callback_data);

        if (! $api->successful) {
            $this->applyFailure($call);

            return LeadCallActionResult::error(502, 'meta_rejected', $api->userMessage());
        }

        $wacid = $api->data['wacid'] ?? null;

        if (! is_string($wacid) || $wacid === '') {
            return LeadCallActionResult::error(502, 'meta_rejected', __('WhatsApp did not return a call id.'));
        }

        if ($this->applyRinging($call, $wacid)) {
            $fresh = LeadCall::query()->where('tenant_id', $lead->tenant_id)->whereKey($call->id)->first() ?? $call;
            LeadCallBroadcaster::updated($fresh, CallStatus::Ringing);

            return LeadCallActionResult::success($fresh);
        }

        // The conditional Initiated -> Ringing UPDATE matched nothing: something
        // else already moved this row on while Meta's connect() was in flight.
        // Meta has now accepted the call and handed back a real wacid — it must
        // never simply be dropped.
        return $this->reconcileLostRace($call, $source, $wacid);
    }

    /**
     * Only one legitimate reason the row can have moved on by itself: the
     * approved local-first cancellation path already won and marked the call
     * Failed/AgentCancelledOutbound. In that case the local cancellation is
     * never reverted — the wacid is persisted for the record and cleaned up
     * at Meta with exactly one terminate, best-effort. Any other state is
     * left untouched and reported the same way an outright connect failure
     * already is.
     */
    private function reconcileLostRace(LeadCall $call, LeadSource $source, string $wacid): LeadCallActionResult
    {
        $current = LeadCall::query()->where('tenant_id', $call->tenant_id)->whereKey($call->id)->first();

        if ($current === null || $current->status !== CallStatus::Failed || $current->end_reason !== CallEndReason::AgentCancelledOutbound) {
            return LeadCallActionResult::error(502, 'meta_rejected', __('WhatsApp did not return a call id.'));
        }

        $this->persistOrphanedWacidAndCleanUp($current, $source, $wacid);

        return LeadCallActionResult::success($current->fresh() ?? $current);
    }

    private function persistOrphanedWacidAndCleanUp(LeadCall $call, LeadSource $source, string $wacid): void
    {
        $applied = LeadCall::query()
            ->where('tenant_id', $call->tenant_id)
            ->whereKey($call->id)
            ->where('status', CallStatus::Failed->value)
            ->where('end_reason', CallEndReason::AgentCancelledOutbound->value)
            ->whereNull('external_call_id')
            ->update([
                'external_call_id' => $wacid,
                'updated_at' => Carbon::now(),
            ]) === 1;

        if (! $applied) {
            // Already reconciled by a previous/duplicate response — never a
            // second terminate for the same wacid.
            return;
        }

        Log::warning('WhatsApp outbound call cancelled locally before Meta accepted connect; wacid persisted and cleaned up', [
            'tenant_id' => $call->tenant_id,
            'lead_call_id' => $call->id,
            'external_call_id' => $wacid,
        ]);

        (new WhatsAppCallingClient($source))->terminate($wacid);
    }

    /**
     * @param  array<int, array{action_name: ?string, can_perform_action: bool, limits: array<int, mixed>}>  $actions
     */
    private function canStartCall(array $actions): bool
    {
        foreach ($actions as $action) {
            if (($action['action_name'] ?? null) === 'start_call') {
                return $action['can_perform_action'] === true;
            }
        }

        return false;
    }

    /**
     * Under a short per-Lead lock (never held across the Meta call above):
     * refuse if an outbound call is already Initiated/Ringing/Connected for
     * this Lead, else create the row. external_call_id is deliberately left
     * NULL — see the migration.
     */
    private function createInitiated(TenantUser $user, Lead $lead, LeadSource $source): ?LeadCall
    {
        return Cache::lock("wa-outbound-initiate:{$lead->id}", 10)->block(5, function () use ($user, $lead, $source): ?LeadCall {
            $active = LeadCall::query()
                ->where('tenant_id', $lead->tenant_id)
                ->where('lead_id', $lead->id)
                ->where('direction', CallDirection::Outbound->value)
                ->whereIn('status', CallStatus::activeValues())
                ->exists();

            if ($active) {
                return null;
            }

            return LeadCall::query()->create([
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'lead_source_id' => $source->id,
                'direction' => CallDirection::Outbound,
                'external_call_id' => null,
                'biz_opaque_callback_data' => (string) Str::ulid(),
                'status' => CallStatus::Initiated,
                'routing_mode' => CallRoutingMode::AgentInitiated,
                'routed_user_ids' => [$user->id],
                'assignee_id_at_arrival' => $lead->assigned_to,
                'claimed_by' => $user->id,
                'claimed_at' => Carbon::now(),
            ]);
        });
    }

    private function applyRinging(LeadCall $call, string $wacid): bool
    {
        return LeadCall::query()
            ->where('tenant_id', $call->tenant_id)
            ->whereKey($call->id)
            ->where('status', CallStatus::Initiated->value)
            ->whereNull('external_call_id')
            ->update([
                'external_call_id' => $wacid,
                'status' => CallStatus::Ringing->value,
                'ringing_expires_at' => Carbon::now()->addSeconds((int) config('calling.ring_ttl_seconds')),
                'updated_at' => Carbon::now(),
            ]) === 1;
    }

    private function applyFailure(LeadCall $call): void
    {
        LeadCall::query()
            ->where('tenant_id', $call->tenant_id)
            ->whereKey($call->id)
            ->where('status', CallStatus::Initiated->value)
            ->update([
                'status' => CallStatus::Failed->value,
                'end_reason' => CallEndReason::OutboundConnectFailed->value,
                'ended_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
    }
}
