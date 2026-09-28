<?php

namespace App\Actions\Tenant;

use App\Concerns\InteractsWithLeadCalls;
use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Support\LeadCallActionResult;
use App\Support\LeadCallBroadcaster;
use App\Support\LeadCallSdp;
use Illuminate\Support\Carbon;

/**
 * WhatsApp inbound calling (Step 4) — a routed user claims a ringing call.
 *
 * The claim is one conditional UPDATE (Ringing + unclaimed + not expired →
 * Answering, claimed_by = the user), so a second claimant, a reject, or a
 * terminate webhook racing it can never both win. Only the winner is handed the
 * SDP offer, decrypted from the cache; nothing is locked across that read.
 *
 *  - Only a user named in the call's frozen routing snapshot, still active and
 *    still holding the lead-management `update` permission (LeadCallPolicy).
 *  - A NEW claim also needs the call's source to be active and calling_enabled;
 *    a repeat claim by the claimant does not (switching calling off stops new
 *    claims, it does not interrupt a call already being answered).
 *  - An expired call is refused without changing its row — expiring it is the
 *    later reconciliation step's job.
 *  - A missing offer means the call can never be answered, so it is moved to
 *    Failed (sdp_cache_failure) and no SDP is returned.
 *  - The claimant repeating the claim (a page reload, a double click) is
 *    idempotent and gets the offer again.
 */
class ClaimLeadCall
{
    use InteractsWithLeadCalls;

    public function handle(TenantUser $user, string $callId): LeadCallActionResult
    {
        $call = $this->findCall($user, $callId);

        if ($call === null || $call->direction !== CallDirection::Inbound) {
            return LeadCallActionResult::notFound();
        }

        $this->authorizeFor($user, $call);

        $source = $this->sourceFor($call);

        if ($source === null) {
            return LeadCallActionResult::notFound();
        }

        if ($call->status === CallStatus::Answering) {
            return $this->repeatClaim($user, $call, $source);
        }

        if ($call->status !== CallStatus::Ringing) {
            return $this->refused($user, $call, 'call_not_active', __('This call is no longer ringing.'));
        }

        if ($this->isExpired($call)) {
            return $this->refused($user, $call, 'call_expired', __('This call has expired.'));
        }

        if (! $source->is_active || ! $source->calling_enabled) {
            return $this->refused($user, $call, 'calling_unavailable', __('Calling is not available for this WhatsApp number.'));
        }

        $offer = LeadCallSdp::readOffer($source, $call->external_call_id);

        if ($offer === null) {
            $failed = $this->scoped($call)
                ->where('status', CallStatus::Ringing->value)
                ->whereNull('claimed_by')
                ->update($this->failedValues(CallEndReason::SdpCacheFailure)) === 1;

            if ($failed) {
                LeadCallBroadcaster::updated($call, CallStatus::Failed);

                return $this->offerUnavailable($user, $call);
            }

            return $this->afterLostClaim($user, $call, $source);
        }

        $won = $this->scoped($call)
            ->where('status', CallStatus::Ringing->value)
            ->whereNull('claimed_by')
            ->where('ringing_expires_at', '>', Carbon::now())
            ->update([
                'status' => CallStatus::Answering->value,
                'claimed_by' => $user->id,
                'claimed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]) === 1;

        if (! $won) {
            return $this->afterLostClaim($user, $call, $source);
        }

        $this->logCallAction('claim', $user, $call, 'claimed');

        LeadCallBroadcaster::updated($call, CallStatus::Answering);

        return LeadCallActionResult::success($this->freshCall($call) ?? $call, $this->session($offer));
    }

    /**
     * A claim that found the call already Answering: idempotent for the
     * claimant, a conflict for anyone else.
     */
    private function repeatClaim(TenantUser $user, LeadCall $call, LeadSource $source): LeadCallActionResult
    {
        if ($call->claimed_by !== $user->id) {
            return $this->refused($user, $call, 'already_claimed', __('Another agent is already answering this call.'));
        }

        if ($this->isExpired($call)) {
            return $this->refused($user, $call, 'call_expired', __('This call has expired.'));
        }

        $offer = LeadCallSdp::readOffer($source, $call->external_call_id);

        if ($offer === null) {
            if ($this->failAnswering($call, $user->id, CallEndReason::SdpCacheFailure)) {
                LeadCallBroadcaster::updated($call, CallStatus::Failed);

                return $this->offerUnavailable($user, $call);
            }

            return $this->refused($user, $call, 'call_state_changed', __('The call changed while your request was in progress.'));
        }

        $this->logCallAction('claim', $user, $call, 'claim_repeated');

        return LeadCallActionResult::success($call, $this->session($offer));
    }

    /**
     * The conditional UPDATE matched nothing: read what the call is now.
     */
    private function afterLostClaim(TenantUser $user, LeadCall $call, LeadSource $source): LeadCallActionResult
    {
        $current = $this->freshCall($call);

        if ($current === null) {
            return LeadCallActionResult::notFound();
        }

        if ($current->status === CallStatus::Answering && $current->claimed_by === $user->id) {
            return $this->repeatClaim($user, $current, $source);
        }

        if ($current->status === CallStatus::Answering) {
            return $this->refused($user, $current, 'already_claimed', __('Another agent is already answering this call.'));
        }

        if ($current->status === CallStatus::Ringing && $this->isExpired($current)) {
            return $this->refused($user, $current, 'call_expired', __('This call has expired.'));
        }

        return $this->refused($user, $current, 'call_not_active', __('This call is no longer ringing.'));
    }

    private function offerUnavailable(TenantUser $user, LeadCall $call): LeadCallActionResult
    {
        $this->logCallAction('claim', $user, $call, 'offer_unavailable');

        return LeadCallActionResult::error(409, 'offer_unavailable', __('This call can no longer be answered.'));
    }

    private function refused(TenantUser $user, LeadCall $call, string $code, string $message): LeadCallActionResult
    {
        $this->logCallAction('claim', $user, $call, $code);

        return LeadCallActionResult::error(409, $code, $message);
    }

    /**
     * @return array{sdp_type: string, sdp: string}
     */
    private function session(string $offer): array
    {
        return ['sdp_type' => 'offer', 'sdp' => $offer];
    }
}
