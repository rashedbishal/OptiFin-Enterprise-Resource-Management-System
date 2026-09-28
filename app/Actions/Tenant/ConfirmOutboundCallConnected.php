<?php

namespace App\Actions\Tenant;

use App\Concerns\InteractsWithLeadCalls;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\TenantUser;
use App\Support\LeadCallActionResult;
use App\Support\LeadCallBroadcaster;
use Illuminate\Support\Carbon;

/**
 * WhatsApp Outbound Calling — the browser's authenticated confirmation that
 * ITS OWN WebRTC peer connection has actually reached a connected ICE state,
 * called only after OutboundSession's own waitForConnection() resolves. This
 * is the one and only writer of `connected_at` for an outbound call.
 *
 * Meta's `connect` webhook (RecordOutboundCallEvent::applyAnswer()) only
 * ever moves a row to Answering, deliberately never Connected — an SDP
 * answer arriving is not evidence the consumer physically picked up (Meta's
 * public documentation does not confirm that interpretation, and a real
 * browser reproduction showed the opposite: the answer can arrive while the
 * consumer's phone is still audibly ringing). Only this action, driven by
 * the browser's own real WebRTC connection, may complete the transition.
 *
 * Deliberately does NOT also require RecordOutboundCallStatusEvent's
 * `consumer_accepted_at` (Meta's documented `ACCEPTED` call-status webhook)
 * — an earlier version of this action did, but real-world validation against
 * live Meta test calls (2026-09-26) showed the consumer genuinely answering
 * and talking while Meta never sent that webhook at all (only `RINGING`
 * arrived). Real ICE-connect is therefore the only currently-reliable signal
 * available and remains the sole gate; `consumer_accepted_at`, when Meta
 * does happen to send it, is still recorded (see RecordOutboundCallStatusEvent)
 * but is informational only here.
 *
 * Mirrors CancelOutboundLeadCall's exact shape: claimant-only (the
 * initiating agent — the only routed user an outbound call ever has), one
 * conditional UPDATE (Answering -> Connected only, never Ringing ->
 * Connected). A lost race (the call already moved on — a terminate webhook
 * or the stale sweep won first) is reported as a clear non-success so the
 * browser tears its local session down rather than declaring itself
 * connected against a call that no longer is. Repeated confirmation of an
 * already-Connected call is idempotent for the claimant.
 */
class ConfirmOutboundCallConnected
{
    use InteractsWithLeadCalls;

    public function handle(TenantUser $user, string $callId): LeadCallActionResult
    {
        $call = $this->findCall($user, $callId);

        if ($call === null || $call->direction !== CallDirection::Outbound) {
            return LeadCallActionResult::notFound();
        }

        $this->authorizeFor($user, $call);

        if ($call->claimed_by !== $user->id) {
            $this->logCallAction('confirm_outbound_connected', $user, $call, 'not_claimant');

            return LeadCallActionResult::error(403, 'not_claimant', __('Only the agent who placed this call can confirm it.'));
        }

        if ($call->status === CallStatus::Connected) {
            $this->logCallAction('confirm_outbound_connected', $user, $call, 'already_connected');

            return LeadCallActionResult::success($call);
        }

        if ($call->status !== CallStatus::Answering) {
            $this->logCallAction('confirm_outbound_connected', $user, $call, 'call_not_confirmable');

            return LeadCallActionResult::error(409, 'call_not_confirmable', __('This call can no longer be confirmed as connected.'));
        }

        $applied = $this->scoped($call)
            ->where('status', CallStatus::Answering->value)
            ->where('claimed_by', $user->id)
            ->update([
                'status' => CallStatus::Connected->value,
                'connected_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]) === 1;

        $fresh = $this->freshCall($call) ?? $call;

        if (! $applied) {
            if ($fresh->status === CallStatus::Connected) {
                $this->logCallAction('confirm_outbound_connected', $user, $call, 'already_connected');

                return LeadCallActionResult::success($fresh);
            }

            $this->logCallAction('confirm_outbound_connected', $user, $call, 'lost_race');

            return LeadCallActionResult::error(409, 'call_not_confirmable', __('This call can no longer be confirmed as connected.'));
        }

        LeadCallBroadcaster::updated($fresh, CallStatus::Connected);
        $this->logCallAction('confirm_outbound_connected', $user, $call, 'connected');

        return LeadCallActionResult::success($fresh);
    }
}
