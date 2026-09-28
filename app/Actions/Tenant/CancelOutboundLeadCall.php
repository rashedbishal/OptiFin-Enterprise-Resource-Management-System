<?php

namespace App\Actions\Tenant;

use App\Concerns\InteractsWithLeadCalls;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Services\WhatsAppCallingClient;
use App\Support\LeadCallActionResult;
use App\Support\LeadCallBroadcaster;
use Illuminate\Support\Carbon;

/**
 * WhatsApp Outbound Calling — the initiating agent cancels their own call
 * before it connects. Mirrors RejectLeadCall's exact safety shape (which it
 * cannot reuse directly: inbound Reject allows any routed user, and has no
 * Initiated state at all) — local state moves first, one conditional UPDATE,
 * then Meta's `terminate` is sent exactly once, never retried, and never
 * rolled back on failure.
 *
 *  - Only the claimant (the one agent who placed the call — never a second
 *    routed user, since there isn't one for an outbound call).
 *  - Allowed only while Initiated or Ringing. A Connected call must use
 *    HangupLeadCall instead — this action refuses it outright.
 *  - Meta terminate is only attempted when external_call_id is already
 *    known (Ringing). A still-Initiated row (Meta's synchronous /calls
 *    response hasn't come back yet) has nothing to terminate — see the
 *    class docblock's note on this narrow, accepted race window.
 *  - Repeated cancel of an already-terminal call is idempotent for the
 *    claimant, matching HangupLeadCall::alreadyEnded()'s exact precedent.
 *
 * Cancelling in the brief window between InitiateOutboundCall's connect()
 * POST being sent and its response arriving moves the LOCAL row to Failed
 * immediately (local-first, by design) without waiting for that response.
 * If Meta's connect had in fact already succeeded by the time it returns,
 * this row is no longer Initiated, so InitiateOutboundCall's own conditional
 * UPDATE loses the race — it detects that (Failed + AgentCancelledOutbound),
 * persists the wacid it was just handed for the record, and issues exactly
 * one Meta terminate as best-effort cleanup, all without ever reverting this
 * action's local cancellation. See InitiateOutboundCall::reconcileLostRace().
 */
class CancelOutboundLeadCall
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

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $decision = $this->cancel($user, $call, $source);

            if ($decision !== null) {
                return $decision;
            }

            $call = $this->freshCall($call);

            if ($call === null) {
                return LeadCallActionResult::notFound();
            }
        }

        return $this->notCancellable($user, $call);
    }

    /**
     * @return LeadCallActionResult|null null = the conditional UPDATE lost; re-read and decide again
     */
    private function cancel(TenantUser $user, LeadCall $call, ?LeadSource $source): ?LeadCallActionResult
    {
        if ($call->claimed_by !== $user->id) {
            return $this->notClaimant($user, $call);
        }

        if (! in_array($call->status, [CallStatus::Initiated, CallStatus::Ringing], true)) {
            return $call->status->isTerminal() ? $this->alreadyEnded($user, $call) : $this->notCancellable($user, $call);
        }

        $won = $this->scoped($call)
            ->where('status', $call->status->value)
            ->where('claimed_by', $user->id)
            ->update($this->cancelledValues()) === 1;

        return $won ? $this->cancelledAtMeta($user, $call, $source) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelledValues(): array
    {
        return [
            'status' => CallStatus::Failed->value,
            'end_reason' => CallEndReason::AgentCancelledOutbound->value,
            'ended_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }

    private function cancelledAtMeta(TenantUser $user, LeadCall $call, ?LeadSource $source): LeadCallActionResult
    {
        LeadCallBroadcaster::updated($call, CallStatus::Failed);

        // Nothing to terminate at Meta while still Initiated — the
        // synchronous connect() response (and so the wacid) hasn't landed.
        $api = ($source !== null && $call->external_call_id !== null)
            ? (new WhatsAppCallingClient($source))->terminate($call->external_call_id)
            : null;

        $this->logCallAction('cancel_outbound', $user, $call, $api?->successful === false ? 'cancelled_meta_unconfirmed' : 'cancelled', $api);

        return LeadCallActionResult::success($this->freshCall($call) ?? $call, metaConfirmed: $api?->successful);
    }

    private function alreadyEnded(TenantUser $user, LeadCall $call): LeadCallActionResult
    {
        $this->logCallAction('cancel_outbound', $user, $call, 'already_ended');

        return LeadCallActionResult::success($call);
    }

    private function notClaimant(TenantUser $user, LeadCall $call): LeadCallActionResult
    {
        $this->logCallAction('cancel_outbound', $user, $call, 'not_claimant');

        return LeadCallActionResult::error(403, 'not_claimant', __('Only the agent who placed this call can cancel it.'));
    }

    private function notCancellable(TenantUser $user, LeadCall $call): LeadCallActionResult
    {
        $this->logCallAction('cancel_outbound', $user, $call, 'call_not_cancellable');

        return LeadCallActionResult::error(409, 'call_not_cancellable', __('This call can no longer be cancelled — use Hang Up once connected.'));
    }
}
