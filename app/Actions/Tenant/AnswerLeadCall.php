<?php

namespace App\Actions\Tenant;

use App\Concerns\InteractsWithLeadCalls;
use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallingApiError;
use App\Enums\CallMetaStage;
use App\Enums\CallStatus;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Services\WhatsAppCallingClient;
use App\Support\CallingApiResult;
use App\Support\LeadCallActionResult;
use App\Support\LeadCallBroadcaster;
use App\Support\LeadCallSdp;
use Illuminate\Support\Carbon;

/**
 * WhatsApp inbound calling (Step 4) — the claimant answers, in the two Meta
 * steps Meta documents: `answer` = `pre_accept`, `accept` = `accept`. They are
 * separate operations on purpose: Meta recommends pre-accepting so the browser's
 * WebRTC connection is up BEFORE the call is accepted (accepting too early
 * clips the caller's first words), so the browser calls `accept` only once its
 * connection is ready.
 *
 * Meta requires the `accept` SDP to equal the `pre_accept` SDP, so the answer is
 * validated, normalized and cached (encrypted) once at `answer`, and `accept`
 * sends that cached copy — it never takes a new SDP.
 *
 * Only the claimant, only while the call is Answering and not expired (an
 * expired call is refused unchanged; expiring it belongs to a later step).
 * Neither step needs calling_enabled — switching calling off stops new claims,
 * not a call already being answered.
 *
 * The Meta request runs under a short per-call CACHE lock (two tabs of one
 * claimant cannot double-send) and with no database transaction or row lock
 * held. Its result is then applied with a conditional UPDATE that re-checks the
 * call is still Answering for the same claimant; if a terminate webhook or a
 * reject got there first, nothing is overwritten and the request is a conflict.
 *
 * A Meta failure is either retryable (Network, Transient, Limit, InvalidResponse
 * — the call stays Answering with its cached answer, the claimant may retry) or
 * permanent (the call moves to Failed and the cached SDPs are dropped). No POST
 * is ever retried automatically.
 */
class AnswerLeadCall
{
    use InteractsWithLeadCalls;

    /** @var array<int, CallingApiError> */
    private const array RETRYABLE = [
        CallingApiError::Network,
        CallingApiError::Transient,
        CallingApiError::Limit,
        CallingApiError::InvalidResponse,
    ];

    /**
     * `answer` — Meta pre_accept.
     */
    public function answer(TenantUser $user, string $callId, mixed $sdpType, mixed $sdp): LeadCallActionResult
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

        if (($refusal = $this->claimantRefusal('answer', $user, $call)) !== null) {
            return $refusal;
        }

        $normalized = $sdpType === 'answer' ? LeadCallSdp::normalizeAnswer($sdp) : null;

        if ($normalized === null) {
            $this->logCallAction('answer', $user, $call, 'invalid_sdp');

            return LeadCallActionResult::error(422, 'invalid_sdp', __('The answer SDP is missing or not valid.'));
        }

        if ($call->meta_stage === CallMetaStage::PreAccepted) {
            return $this->repeatedAnswer($user, $call, $source, $normalized);
        }

        return $this->withActionLock($call, function () use ($user, $call, $source, $normalized): LeadCallActionResult {
            $current = $this->freshCall($call);

            if ($current === null) {
                return LeadCallActionResult::notFound();
            }

            if (($refusal = $this->claimantRefusal('answer', $user, $current)) !== null) {
                return $refusal;
            }

            if ($current->meta_stage === CallMetaStage::PreAccepted) {
                return $this->repeatedAnswer($user, $current, $source, $normalized);
            }

            // A retry after a retryable failure must send the SAME answer the
            // first attempt did (Meta may already have applied it, and the
            // browser applied it locally), so a cached answer wins and a
            // different one is refused.
            $cached = LeadCallSdp::readAnswer($source, $current->external_call_id);

            if ($cached !== null && $cached !== $normalized) {
                $this->logCallAction('answer', $user, $current, 'sdp_mismatch');

                return LeadCallActionResult::error(409, 'sdp_mismatch', __('Send the same answer SDP as your first attempt.'));
            }

            if ($cached === null && ! LeadCallSdp::storeAnswer($source, $current->external_call_id, $normalized)) {
                $this->logCallAction('answer', $user, $current, 'sdp_cache_unavailable');

                return LeadCallActionResult::error(503, 'sdp_cache_unavailable', __('The call could not be processed right now. Try again.'), true);
            }

            $api = (new WhatsAppCallingClient($source))->preAccept($current->external_call_id, $normalized);

            if (! $api->successful) {
                return $this->metaFailure('answer', $user, $current, $source, $api, CallEndReason::PreAcceptFailed);
            }

            $applied = $this->scoped($current)
                ->where('status', CallStatus::Answering->value)
                ->where('claimed_by', $user->id)
                ->whereNull('meta_stage')
                ->update(['meta_stage' => CallMetaStage::PreAccepted->value, 'updated_at' => Carbon::now()]) === 1;

            if (! $applied) {
                return $this->stateChanged('answer', $user, $current, $source);
            }

            $this->logCallAction('answer', $user, $current, 'pre_accepted', $api);

            LeadCallBroadcaster::updated($current, CallStatus::Answering);

            return LeadCallActionResult::success($this->freshCall($current) ?? $current);
        });
    }

    /**
     * `accept` — Meta accept, with the answer cached at `answer`.
     */
    public function accept(TenantUser $user, string $callId): LeadCallActionResult
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

        if (($done = $this->alreadyConnected('accept', $user, $call)) !== null) {
            return $done;
        }

        if (($refusal = $this->claimantRefusal('accept', $user, $call)) !== null) {
            return $refusal;
        }

        if ($call->meta_stage !== CallMetaStage::PreAccepted) {
            $this->logCallAction('accept', $user, $call, 'pre_accept_required');

            return LeadCallActionResult::error(409, 'pre_accept_required', __('Answer the call before accepting it.'));
        }

        return $this->withActionLock($call, function () use ($user, $call, $source): LeadCallActionResult {
            $current = $this->freshCall($call);

            if ($current === null) {
                return LeadCallActionResult::notFound();
            }

            if (($done = $this->alreadyConnected('accept', $user, $current)) !== null) {
                return $done;
            }

            if (($refusal = $this->claimantRefusal('accept', $user, $current)) !== null) {
                return $refusal;
            }

            if ($current->meta_stage !== CallMetaStage::PreAccepted) {
                return LeadCallActionResult::error(409, 'pre_accept_required', __('Answer the call before accepting it.'));
            }

            $answer = LeadCallSdp::readAnswer($source, $current->external_call_id);

            if ($answer === null) {
                // Without the exact pre_accept SDP the call cannot be accepted.
                if ($this->failAnswering($current, $user->id, CallEndReason::SdpCacheFailure)) {
                    LeadCallBroadcaster::updated($current, CallStatus::Failed);
                }

                $this->forgetSdp($current, $source);
                $this->logCallAction('accept', $user, $current, 'answer_unavailable');

                return LeadCallActionResult::error(409, 'answer_unavailable', __('This call can no longer be answered.'));
            }

            $api = (new WhatsAppCallingClient($source))->accept($current->external_call_id, $answer);

            if (! $api->successful) {
                return $this->metaFailure('accept', $user, $current, $source, $api, CallEndReason::AcceptFailed);
            }

            $applied = $this->scoped($current)
                ->where('status', CallStatus::Answering->value)
                ->where('claimed_by', $user->id)
                ->where('meta_stage', CallMetaStage::PreAccepted->value)
                ->update([
                    'status' => CallStatus::Connected->value,
                    'connected_at' => Carbon::now(),
                    'meta_stage' => CallMetaStage::Accepted->value,
                    'updated_at' => Carbon::now(),
                ]) === 1;

            if (! $applied) {
                return $this->stateChanged('accept', $user, $current, $source);
            }

            $this->forgetSdp($current, $source);
            $this->logCallAction('accept', $user, $current, 'connected', $api);

            LeadCallBroadcaster::updated($current, CallStatus::Connected);

            return LeadCallActionResult::success($this->freshCall($current) ?? $current);
        });
    }

    /**
     * A repeated `answer` for a call that is already pre-accepted. It stays an
     * idempotent 200 that never calls Meta again — but only when the submitted
     * answer IS the one Meta was given. A different SDP (a second browser tab of
     * the same claimant, say) is a 409 that changes nothing and overwrites
     * nothing: it could otherwise be "accepted" while the audio belongs to a peer
     * connection Meta never negotiated with. If the cached answer is gone the
     * two cannot be compared, which is treated the same way.
     */
    private function repeatedAnswer(TenantUser $user, LeadCall $call, LeadSource $source, string $normalized): LeadCallActionResult
    {
        $cached = LeadCallSdp::readAnswer($source, $call->external_call_id);

        if ($cached === null || $cached !== $normalized) {
            $this->logCallAction('answer', $user, $call, 'sdp_mismatch');

            return LeadCallActionResult::error(409, 'sdp_mismatch', __('Send the same answer SDP as your first attempt.'));
        }

        $this->logCallAction('answer', $user, $call, 'answer_repeated');

        return LeadCallActionResult::success($call);
    }

    /**
     * Why this user cannot take the next answering step on this call right now,
     * or null when they can: only the claimant, only while Answering, and not
     * once the call has expired (which leaves the row untouched).
     */
    private function claimantRefusal(string $action, TenantUser $user, LeadCall $call): ?LeadCallActionResult
    {
        if ($call->status === CallStatus::Ringing) {
            return $this->refuse($action, $user, $call, 409, 'call_not_claimed', __('Claim the call before answering it.'));
        }

        if ($call->status !== CallStatus::Answering) {
            return $this->refuse($action, $user, $call, 409, 'call_not_answerable', __('This call is no longer being answered.'));
        }

        if ($call->claimed_by !== $user->id) {
            return $this->refuse($action, $user, $call, 403, 'not_claimant', __('Only the agent who claimed this call can answer it.'));
        }

        if ($this->isExpired($call)) {
            return $this->refuse($action, $user, $call, 409, 'call_expired', __('This call has expired.'));
        }

        return null;
    }

    /**
     * A repeated accept by the claimant of a call that is already Connected is
     * idempotent and never calls Meta again; anyone else gets the claimant refusal.
     */
    private function alreadyConnected(string $action, TenantUser $user, LeadCall $call): ?LeadCallActionResult
    {
        if ($call->status !== CallStatus::Connected) {
            return null;
        }

        if ($call->claimed_by !== $user->id) {
            return $this->refuse($action, $user, $call, 403, 'not_claimant', __('Only the agent who claimed this call can answer it.'));
        }

        $this->logCallAction($action, $user, $call, 'accept_repeated');

        return LeadCallActionResult::success($call);
    }

    private function refuse(string $action, TenantUser $user, LeadCall $call, int $status, string $code, string $message): LeadCallActionResult
    {
        $this->logCallAction($action, $user, $call, $code);

        return LeadCallActionResult::error($status, $code, $message);
    }

    /**
     * Retryable failures leave the call Answering with its cached answer; every
     * other failure ends it as Failed and drops the cached SDPs. The message is
     * fixed text — Meta's own wording is never surfaced.
     */
    private function metaFailure(string $action, TenantUser $user, LeadCall $call, LeadSource $source, CallingApiResult $api, CallEndReason $reason): LeadCallActionResult
    {
        if ($api->error !== null && in_array($api->error, self::RETRYABLE, true)) {
            $this->logCallAction($action, $user, $call, 'meta_retryable', $api);

            return LeadCallActionResult::error(
                $api->error === CallingApiError::InvalidResponse ? 502 : 503,
                'meta_unavailable',
                __('WhatsApp did not confirm the request. Try again.'),
                true,
            );
        }

        if (! $this->failAnswering($call, $user->id, $reason)) {
            return $this->stateChanged($action, $user, $call, $source);
        }

        $this->forgetSdp($call, $source);
        $this->logCallAction($action, $user, $call, 'meta_failed', $api);

        LeadCallBroadcaster::updated($call, CallStatus::Failed);

        return LeadCallActionResult::error(502, 'meta_rejected', __('WhatsApp could not connect this call.'));
    }

    /**
     * The conditional UPDATE that applies a Meta result matched nothing: the
     * call moved on (a terminate webhook, a reject) while Meta was being asked.
     * The newer state is left exactly as it is.
     */
    private function stateChanged(string $action, TenantUser $user, LeadCall $call, LeadSource $source): LeadCallActionResult
    {
        $this->forgetSdp($call, $source);
        $this->logCallAction($action, $user, $call, 'call_state_changed');

        return LeadCallActionResult::error(409, 'call_state_changed', __('The call changed while your request was in progress.'));
    }
}
