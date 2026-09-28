<?php

namespace App\Actions\Tenant;

use App\Concerns\InteractsWithLeadCalls;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\TenantUser;
use App\Support\LeadCallActionResult;
use App\Support\LeadCallSdp;

/**
 * WhatsApp Outbound Calling — the narrow, claimant-only read that hands the
 * initiating agent's browser Meta's SDP answer once RecordOutboundCallEvent
 * has cached it (the mirror of ClaimLeadCall handing out the inbound
 * offer). `call.updated` never carries the SDP itself (unchanged
 * LeadCallUpdated contract) — the browser always fetches it here instead.
 *
 * Repeat-safe, not one-time: a reload or a second tab of the same claimant
 * gets the same cached answer again, exactly like a repeated claim.
 * Answered "not yet available" (retryable) before the call has at least
 * reached Answering (Meta's SDP answer cached — see RecordOutboundCallEvent
 * — but the browser has not yet confirmed a real WebRTC connection via
 * ConfirmOutboundCallConnected), and "no longer available" once terminal or
 * if the cache has expired — never a stale answer for a call that has
 * already ended.
 */
class FetchOutboundCallAnswer
{
    use InteractsWithLeadCalls;

    public function handle(TenantUser $user, string $callId): LeadCallActionResult
    {
        $call = $this->findCall($user, $callId);

        if ($call === null || $call->direction !== CallDirection::Outbound) {
            return LeadCallActionResult::notFound();
        }

        $this->authorizeFor($user, $call);

        $source = $this->sourceFor($call);

        if ($source === null) {
            return LeadCallActionResult::notFound();
        }

        if ($call->status->isTerminal()) {
            $this->logCallAction('outbound_answer', $user, $call, 'call_ended');

            return LeadCallActionResult::error(409, 'call_not_active', __('This call is no longer active.'));
        }

        if (! in_array($call->status, [CallStatus::Answering, CallStatus::Connected], true)) {
            $this->logCallAction('outbound_answer', $user, $call, 'not_yet_available');

            return LeadCallActionResult::error(409, 'answer_not_yet_available', __('WhatsApp has not answered this call yet.'), true);
        }

        $answer = LeadCallSdp::readAnswer($source, (string) $call->external_call_id);

        if ($answer === null) {
            $this->logCallAction('outbound_answer', $user, $call, 'answer_unavailable');

            return LeadCallActionResult::error(409, 'answer_unavailable', __('This call can no longer be answered.'));
        }

        $this->logCallAction('outbound_answer', $user, $call, 'answer_fetched');

        return LeadCallActionResult::success($call, ['sdp_type' => 'answer', 'sdp' => $answer]);
    }
}
