<?php

namespace App\Actions\Tenant;

use App\Concerns\InteractsWithLeadCalls;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Services\WhatsAppCallingClient;
use App\Support\CallingApiResult;
use App\Support\LeadCallActionResult;
use App\Support\LeadCallBroadcaster;
use Illuminate\Support\Carbon;

/**
 * WhatsApp inbound calling (Step 4) — a user declines a call.
 *
 * Local first: one conditional UPDATE moves the call to Rejected (so a claim or
 * an answer racing it can no longer win), and only then is Meta told, exactly
 * once and never retried. A Meta failure does not roll the local state back —
 * the agent's decision stands, and the caller's ring simply ends by Meta's own
 * timeout with the terminate webhook filling in the rest.
 *
 *  - Ringing: any routed user may reject; claiming first is not required
 *    (end_reason agent_rejected).
 *  - Answering: only the claimant (end_reason agent_cancelled_during_answer).
 *  - Rejecting an already-Rejected call is an idempotent no-op; a Connected or
 *    otherwise finished call is a 409 (ending a live call is a hangup, which
 *    this step does not have).
 *
 * calling_enabled is deliberately not consulted: declining a call is always
 * allowed, like disabling calling is.
 */
class RejectLeadCall
{
    use InteractsWithLeadCalls;

    public function handle(TenantUser $user, string $callId): LeadCallActionResult
    {
        $call = $this->findCall($user, $callId);

        if ($call === null) {
            return LeadCallActionResult::notFound();
        }

        $this->authorizeFor($user, $call);

        $source = $this->sourceFor($call);

        // The state can move under us (a racing claim), so a lost conditional
        // UPDATE is re-read once and decided again.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $decision = $this->reject($user, $call, $source);

            if ($decision !== null) {
                return $decision;
            }

            $call = $this->freshCall($call);

            if ($call === null) {
                return LeadCallActionResult::notFound();
            }
        }

        return $this->conflict($user, $call);
    }

    /**
     * @return LeadCallActionResult|null null = the conditional UPDATE lost; re-read and decide again
     */
    private function reject(TenantUser $user, LeadCall $call, ?LeadSource $source): ?LeadCallActionResult
    {
        switch ($call->status) {
            case CallStatus::Ringing:
                $won = $this->scoped($call)
                    ->where('status', CallStatus::Ringing->value)
                    ->whereNull('claimed_by')
                    ->update($this->rejectedValues(CallEndReason::AgentRejected)) === 1;

                return $won ? $this->rejectedAtMeta($user, $call, $source) : null;

            case CallStatus::Answering:
                if ($call->claimed_by !== $user->id) {
                    return $this->notClaimant($user, $call);
                }

                $won = $this->scoped($call)
                    ->where('status', CallStatus::Answering->value)
                    ->where('claimed_by', $user->id)
                    ->update($this->rejectedValues(CallEndReason::AgentCancelledDuringAnswer)) === 1;

                return $won ? $this->rejectedAtMeta($user, $call, $source) : null;

            case CallStatus::Rejected:
                return $this->alreadyRejected($user, $call);

            default:
                return $this->conflict($user, $call);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rejectedValues(CallEndReason $reason): array
    {
        return [
            'status' => CallStatus::Rejected->value,
            'end_reason' => $reason->value,
            'ended_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }

    /**
     * The local reject has committed; now tell Meta, once. Whatever Meta says,
     * the call stays Rejected.
     */
    private function rejectedAtMeta(TenantUser $user, LeadCall $call, ?LeadSource $source): LeadCallActionResult
    {
        // The local rejection has committed: tell the other routed users now,
        // before the Meta request, so their ringing cards clear immediately.
        LeadCallBroadcaster::updated($call, CallStatus::Rejected);

        $api = $source === null
            ? null
            : (new WhatsAppCallingClient($source))->reject($call->external_call_id);

        $this->forgetSdp($call, $source);
        $this->logCallAction('reject', $user, $call, $api?->successful ? 'rejected' : 'rejected_meta_unconfirmed', $api);

        return LeadCallActionResult::success($this->freshCall($call) ?? $call, metaConfirmed: $this->confirmed($api));
    }

    private function confirmed(?CallingApiResult $api): bool
    {
        return $api !== null && $api->successful;
    }

    /**
     * A repeat reject is a no-op for anyone entitled to it. The row does not
     * record who rejected a Ringing call, so any routed user repeating it is
     * accepted; for a call rejected while Answering only that claimant is.
     */
    private function alreadyRejected(TenantUser $user, LeadCall $call): LeadCallActionResult
    {
        if ($call->end_reason === CallEndReason::AgentCancelledDuringAnswer && $call->claimed_by !== $user->id) {
            return $this->notClaimant($user, $call);
        }

        if ($call->end_reason !== CallEndReason::AgentRejected && $call->end_reason !== CallEndReason::AgentCancelledDuringAnswer) {
            return $this->conflict($user, $call);
        }

        $this->logCallAction('reject', $user, $call, 'reject_repeated');

        return LeadCallActionResult::success($call);
    }

    private function notClaimant(TenantUser $user, LeadCall $call): LeadCallActionResult
    {
        $this->logCallAction('reject', $user, $call, 'not_claimant');

        return LeadCallActionResult::error(403, 'not_claimant', __('Only the agent answering this call can reject it.'));
    }

    private function conflict(TenantUser $user, LeadCall $call): LeadCallActionResult
    {
        $this->logCallAction('reject', $user, $call, 'call_not_rejectable');

        return LeadCallActionResult::error(409, 'call_not_rejectable', __('This call can no longer be rejected.'));
    }
}
