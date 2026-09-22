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
 * WhatsApp inbound calling (Step 7a) — the claimant ends a Connected call from
 * the browser. Not part of Step 5's approved scope (D2 there was "no server
 * hangup") — a separate, explicitly approved additive build step.
 *
 * Local first, exactly like RejectLeadCall: one conditional UPDATE moves the
 * call from Connected to Completed (end_reason agent_hangup) so a racing
 * terminate webhook or the Step 6 stale-call sweep can no longer both win, and
 * only then is Meta's `terminate` sent, exactly once and never retried. A Meta
 * failure does not roll the local state back — the browser's own WebRTC/audio
 * is already torn down by the time this runs, so leaving the row "Connected"
 * would be actively wrong regardless of what Meta's API says. No change to
 * CallStatus was needed: Connected -> Completed was already a legal edge.
 *
 * Only the current claimant of a Connected call may hang it up (a Connected
 * call has exactly one claimant, fixed since it was claimed). A call that is
 * already terminal for ANY reason — this user's own prior hangup, the
 * caller's own terminate, or the stale sweep — is an idempotent success for
 * the claimant: the agent's goal ("this call is over") is already satisfied,
 * whichever writer got there first. Anyone else still gets the claimant
 * refusal. Ringing and Answering are untouched: Reject/Decline already covers
 * them, and this action refuses them outright.
 */
class HangupLeadCall
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

        // The state can move under us (a terminate webhook, the stale sweep), so
        // a lost conditional UPDATE is re-read once and decided again.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $decision = $this->hangup($user, $call, $source);

            if ($decision !== null) {
                return $decision;
            }

            $call = $this->freshCall($call);

            if ($call === null) {
                return LeadCallActionResult::notFound();
            }
        }

        return $this->notConnected($user, $call);
    }

    /**
     * @return LeadCallActionResult|null null = the conditional UPDATE lost; re-read and decide again
     */
    private function hangup(TenantUser $user, LeadCall $call, ?LeadSource $source): ?LeadCallActionResult
    {
        if ($call->status !== CallStatus::Connected) {
            return $call->status->isTerminal() ? $this->alreadyEnded($user, $call) : $this->notConnected($user, $call);
        }

        if ($call->claimed_by !== $user->id) {
            return $this->notClaimant($user, $call);
        }

        $won = $this->scoped($call)
            ->where('status', CallStatus::Connected->value)
            ->where('claimed_by', $user->id)
            ->update($this->completedValues($call)) === 1;

        return $won ? $this->hungUpAtMeta($user, $call, $source) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function completedValues(LeadCall $call): array
    {
        $now = Carbon::now();

        return [
            'status' => CallStatus::Completed->value,
            'end_reason' => CallEndReason::AgentHangup->value,
            'ended_at' => $now,
            // A raw query-builder update() (required for the compare-and-set)
            // bypasses the model's `duration_seconds => integer` cast, and
            // diffInSeconds() can return a float when both timestamps carry
            // microsecond precision (real Postgres does; SQLite in tests may
            // not) — Postgres' strictly-typed integer column then rejects it
            // outright. Cast explicitly rather than relying on a cast that
            // never runs on this write path.
            'duration_seconds' => $call->connected_at !== null ? (int) round($call->connected_at->diffInSeconds($now)) : null,
            'updated_at' => $now,
        ];
    }

    /**
     * The local hangup has committed; now tell Meta, once. Whatever Meta says,
     * the call stays Completed.
     */
    private function hungUpAtMeta(TenantUser $user, LeadCall $call, ?LeadSource $source): LeadCallActionResult
    {
        // The local transition has committed: tell the other routed users now,
        // before the Meta request, so their view of this call updates immediately.
        LeadCallBroadcaster::updated($call, CallStatus::Completed);

        $api = $source === null
            ? null
            : (new WhatsAppCallingClient($source))->terminate($call->external_call_id);

        $this->forgetSdp($call, $source);
        $this->logCallAction('hangup', $user, $call, $api?->successful ? 'hung_up' : 'hung_up_meta_unconfirmed', $api);

        return LeadCallActionResult::success($this->freshCall($call) ?? $call, metaConfirmed: $this->confirmed($api));
    }

    private function confirmed(?CallingApiResult $api): bool
    {
        return $api !== null && $api->successful;
    }

    /**
     * The call is already terminal by the time we act on it. Idempotent success
     * for the claimant (whichever writer ended it, the agent's intent is already
     * satisfied); anyone else still gets the claimant refusal.
     */
    private function alreadyEnded(TenantUser $user, LeadCall $call): LeadCallActionResult
    {
        if ($call->claimed_by !== $user->id) {
            return $this->notClaimant($user, $call);
        }

        $this->logCallAction('hangup', $user, $call, 'already_ended');

        return LeadCallActionResult::success($call);
    }

    private function notClaimant(TenantUser $user, LeadCall $call): LeadCallActionResult
    {
        $this->logCallAction('hangup', $user, $call, 'not_claimant');

        return LeadCallActionResult::error(403, 'not_claimant', __('Only the agent on this call can end it.'));
    }

    private function notConnected(TenantUser $user, LeadCall $call): LeadCallActionResult
    {
        $this->logCallAction('hangup', $user, $call, 'call_not_connected');

        return LeadCallActionResult::error(409, 'call_not_connected', __('This call is not connected.'));
    }
}
