<?php

namespace App\Concerns;

use App\Enums\CallEndReason;
use App\Enums\CallRoutingMode;
use App\Enums\CallStatus;
use App\Enums\LeadSourceType;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Models\TenantUser;
use App\Support\CallingApiResult;
use App\Support\LeadCallActionResult;
use App\Support\LeadCallSdp;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp inbound calling (Step 4) — what ClaimLeadCall, RejectLeadCall and
 * AnswerLeadCall share.
 *
 * Every state change is a single conditional UPDATE (compare-and-set) whose
 * WHERE clause names the state it expects, so exactly one of any set of racing
 * requests — a second claimant, a reject against a claim, a terminate webhook
 * against an answer — can win, on PostgreSQL and SQLite alike. Zero rows updated
 * means someone else got there first, and the loser never overwrites the newer
 * state. No transaction, row lock or lead lock is ever held across a Meta
 * request or a cache call, and none of these actions locks the lead row (the
 * established lock order is lead, then call — this code takes neither).
 */
trait InteractsWithLeadCalls
{
    /**
     * The call, looked up inside the acting user's own tenant — a forged or
     * cross-tenant id is simply not found.
     */
    private function findCall(TenantUser $user, string $callId): ?LeadCall
    {
        return LeadCall::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($callId)
            ->first();
    }

    private function freshCall(LeadCall $call): ?LeadCall
    {
        return LeadCall::query()
            ->where('tenant_id', $call->tenant_id)
            ->whereKey($call->id)
            ->first();
    }

    /**
     * The call's own WhatsApp source, resolved from the call row itself
     * (never from anything the browser sent) and required to be in the same
     * tenant.
     */
    private function sourceFor(LeadCall $call): ?LeadSource
    {
        return LeadSource::query()
            ->where('tenant_id', $call->tenant_id)
            ->where('type', LeadSourceType::Whatsapp->value)
            ->whereKey($call->lead_source_id)
            ->first();
    }

    private function authorizeFor(TenantUser $user, LeadCall $call): void
    {
        Gate::forUser($user)->authorize('answer', $call);
    }

    /**
     * @return Builder<LeadCall>
     */
    private function scoped(LeadCall $call): Builder
    {
        return LeadCall::query()
            ->where('tenant_id', $call->tenant_id)
            ->whereKey($call->id);
    }

    private function isExpired(LeadCall $call): bool
    {
        return $call->ringing_expires_at === null || ! $call->ringing_expires_at->isFuture();
    }

    /**
     * Moves a still-Answering call of this claimant to Failed. Returns whether
     * this request made the change (false = the call had already moved on).
     */
    private function failAnswering(LeadCall $call, string $claimantId, CallEndReason $reason): bool
    {
        return $this->scoped($call)
            ->where('status', CallStatus::Answering->value)
            ->where('claimed_by', $claimantId)
            ->update($this->failedValues($reason)) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function failedValues(CallEndReason $reason): array
    {
        return [
            'status' => CallStatus::Failed->value,
            'end_reason' => $reason->value,
            'ended_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }

    /**
     * A short cache lock around a Meta request, so two tabs of one claimant
     * cannot send duplicate operations. Never a database lock; it expires by
     * itself, so a crashed request cannot wedge a call. If the lock cannot be
     * evaluated the request is refused (retryable) rather than run unprotected.
     *
     * @param  Closure(): LeadCallActionResult  $callback
     */
    private function withActionLock(LeadCall $call, Closure $callback): LeadCallActionResult
    {
        try {
            $lock = Cache::lock("wa-call-action:{$call->id}", (int) config('calling.action_lock_seconds'));
            $acquired = $lock->get();
        } catch (Throwable) {
            return LeadCallActionResult::error(503, 'action_lock_unavailable', __('The call could not be processed right now. Try again.'), true);
        }

        if (! $acquired) {
            return LeadCallActionResult::error(409, 'action_in_progress', __('Another request for this call is still in progress.'), true);
        }

        try {
            return $callback();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Expires on its own.
            }
        }
    }

    private function forgetSdp(LeadCall $call, ?LeadSource $source): void
    {
        if ($source !== null) {
            LeadCallSdp::forget($source, $call->external_call_id);
        }
    }

    /**
     * Ids and outcome only — never an SDP, token, phone number or Meta text.
     */
    private function logCallAction(string $action, TenantUser $user, LeadCall $call, string $outcome, ?CallingApiResult $api = null): void
    {
        Log::channel('whatsapp')->info('WA CALL ACTION', [
            'tenant_id' => $call->tenant_id,
            'lead_call_id' => $call->id,
            'lead_source_id' => $call->lead_source_id,
            'action' => $action,
            'outcome' => $outcome,
            'category' => $api?->error?->value,
            'meta_code' => $api?->metaCode,
            'http_status' => $api?->httpStatus,
            'fbtrace_id' => $api?->fbtraceId,
            'duration_ms' => $api?->durationMs,
            'tenant_user_id' => $user->id,
            // WhatsApp Call Backup / Overflow Receiver — see actedAs()'s own
            // docblock for the full derivation reasoning.
            'acted_as' => $this->actedAs($call),
        ]);
    }

    /**
     * WhatsApp Call Backup / Overflow Receiver — the acting user's
     * relationship to this call for THIS log line, derived purely from the
     * call's own frozen routing_mode (App\Services\CallRouter's routing
     * snapshot, set once at arrival/placement and never recomputed — see
     * that class's own docblock). Never reads $user at all: every caller of
     * logCallAction() has already passed authorizeFor() (Gate::authorize
     * ('answer', $call), which requires $user->id to be in
     * $call->routed_user_ids) before logging, or — for the handful of
     * pre-authorization refusal logs above that call — the acting user was
     * never granted anything either way, so there is no "who acted"
     * ambiguity to resolve differently in that case; the call's own
     * routing_mode is what the log entry is about.
     *
     * routing_mode alone is sufficient and unambiguous, exactly like
     * App\Actions\Tenant\ListActiveLeadCalls::receivingAs() (same
     * reasoning, same value set, deliberately kept consistent): Assignee
     * only ever routes to the one assignee, BackupFallback only ever routes
     * to eligible backups (the original assignee is explicitly excluded —
     * see CallRouter), AdminFallback only ever routes to admins,
     * AgentInitiated only ever routes to the one agent who placed it. A
     * claimed_by actor was themselves necessarily drawn from
     * routed_user_ids at claim time (ClaimLeadCall's own CAS requires
     * authorizeFor() first), so this stays correct for every action taken
     * after a claim too — hangup, accept, cancel — never assuming the
     * current actor is the original assignee merely because they are
     * acting on the call.
     *
     * CallRoutingMode::None is unreachable in practice (a None call is
     * never rung and never actionable — see ListActiveLeadCalls' identical
     * note); the fallback exists only because PHP's match requires
     * exhaustive case handling.
     */
    private function actedAs(LeadCall $call): string
    {
        return match ($call->routing_mode) {
            CallRoutingMode::Assignee => 'assignee',
            CallRoutingMode::BackupFallback => 'backup',
            CallRoutingMode::AdminFallback => 'admin_fallback',
            CallRoutingMode::AgentInitiated => 'agent_initiated',
            CallRoutingMode::None => 'admin_fallback',
        };
    }
}
