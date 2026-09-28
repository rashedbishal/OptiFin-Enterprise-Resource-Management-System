<?php

namespace App\Http\Controllers;

use App\Actions\Tenant\AnswerLeadCall;
use App\Actions\Tenant\CancelOutboundLeadCall;
use App\Actions\Tenant\ClaimLeadCall;
use App\Actions\Tenant\ConfirmOutboundCallConnected;
use App\Actions\Tenant\FetchOutboundCallAnswer;
use App\Actions\Tenant\HangupLeadCall;
use App\Actions\Tenant\InitiateOutboundCall;
use App\Actions\Tenant\RejectLeadCall;
use App\Models\Lead;
use App\Models\TenantUser;
use App\Support\LeadCallActionResult;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WhatsApp inbound calling (Steps 4 and 7a) — the thin JSON surface over the
 * claim / answer / accept / reject / hangup actions. Everything that matters
 * (tenant-scoped lookup, authorization, state checks, Meta) lives in the
 * actions, so it holds for any caller, not just these routes. The call id in
 * the URL is the only identifier taken from the browser: no lead, source,
 * tenant, phone number id or credential is ever accepted.
 *
 * Responses are never cacheable — a claim's body carries the SDP offer.
 */
class LeadCallController extends Controller
{
    public function claim(Request $request, string $leadCall, ClaimLeadCall $action): JsonResponse
    {
        return $this->respond($request, fn (TenantUser $user): LeadCallActionResult => $action->handle($user, $leadCall));
    }

    /**
     * WhatsApp Outbound Calling — an agent places a call to $lead. Route-
     * model-bound like every other Lead route; InitiateOutboundCall's own
     * Gate::authorize('update', $lead) (LeadPolicy, tenant-scoped) is what
     * actually refuses a cross-tenant lead — the same trust pattern this
     * module already uses everywhere else, never the binding itself.
     */
    public function initiateOutbound(Request $request, Lead $lead, InitiateOutboundCall $action): JsonResponse
    {
        return $this->respond($request, fn (TenantUser $user): LeadCallActionResult => $action->handle($user, $lead, (string) $request->input('sdp')));
    }

    public function answer(Request $request, string $leadCall, AnswerLeadCall $action): JsonResponse
    {
        return $this->respond($request, fn (TenantUser $user): LeadCallActionResult => $action->answer(
            $user,
            $leadCall,
            $request->input('sdp_type'),
            $request->input('sdp'),
        ));
    }

    public function accept(Request $request, string $leadCall, AnswerLeadCall $action): JsonResponse
    {
        return $this->respond($request, fn (TenantUser $user): LeadCallActionResult => $action->accept($user, $leadCall));
    }

    public function reject(Request $request, string $leadCall, RejectLeadCall $action): JsonResponse
    {
        return $this->respond($request, fn (TenantUser $user): LeadCallActionResult => $action->handle($user, $leadCall));
    }

    public function hangup(Request $request, string $leadCall, HangupLeadCall $action): JsonResponse
    {
        return $this->respond($request, fn (TenantUser $user): LeadCallActionResult => $action->handle($user, $leadCall));
    }

    /**
     * WhatsApp Outbound Calling — cancel an Initiated/Ringing call this
     * agent placed, before it connects. A Connected call must use hangup()
     * above instead — CancelOutboundLeadCall refuses it outright.
     */
    public function cancelOutbound(Request $request, string $leadCall, CancelOutboundLeadCall $action): JsonResponse
    {
        return $this->respond($request, fn (TenantUser $user): LeadCallActionResult => $action->handle($user, $leadCall));
    }

    /**
     * WhatsApp Outbound Calling — the narrow, repeat-safe read that hands
     * the claimant Meta's SDP answer once it has been recorded. Never
     * cacheable, same as every other action here — the body carries the SDP.
     */
    public function outboundAnswer(Request $request, string $leadCall, FetchOutboundCallAnswer $action): JsonResponse
    {
        return $this->respond($request, fn (TenantUser $user): LeadCallActionResult => $action->handle($user, $leadCall));
    }

    /**
     * WhatsApp Outbound Calling — the browser's own authenticated
     * confirmation that its real WebRTC connection is up, called only after
     * OutboundSession's waitForConnection() resolves. The one and only path
     * that moves Answering -> Connected — never triggered by any webhook.
     */
    public function outboundConnected(Request $request, string $leadCall, ConfirmOutboundCallConnected $action): JsonResponse
    {
        return $this->respond($request, fn (TenantUser $user): LeadCallActionResult => $action->handle($user, $leadCall));
    }

    /**
     * @param  Closure(TenantUser): LeadCallActionResult  $run
     */
    private function respond(Request $request, Closure $run): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof TenantUser, 401);

        try {
            $result = $run($user);
        } catch (AuthorizationException) {
            $result = LeadCallActionResult::error(403, 'forbidden', __('You cannot act on this call.'));
        }

        return $this->render($result, $user)->header('Cache-Control', 'no-store, private');
    }

    private function render(LeadCallActionResult $result, TenantUser $user): JsonResponse
    {
        if (! $result->ok || $result->call === null) {
            return response()->json(['error' => [
                'code' => $result->errorCode,
                'message' => $result->message,
                'retryable' => $result->retryable,
            ]], $result->status);
        }

        $data = ['call' => [
            'id' => $result->call->id,
            'status' => $result->call->status->value,
            'claimed_by_me' => $result->call->claimed_by === $user->id,
        ]];

        if ($result->session !== null) {
            $data['session'] = $result->session;
        }

        if ($result->metaConfirmed !== null) {
            $data['meta_confirmed'] = $result->metaConfirmed;
        }

        return response()->json(['data' => $data]);
    }
}
