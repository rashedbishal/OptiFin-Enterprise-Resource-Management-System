<?php

namespace App\Actions\Tenant;

use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\LeadCall;
use App\Support\LeadCallBroadcaster;
use App\Support\LeadCallMissedNotifier;
use App\Support\LeadCallSdp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp inbound calling (Step 6) — ends calls whose Meta `terminate` never
 * (or not yet) arrived, so a lost webhook can no longer leave a row active
 * forever (blocking the lead's deletion and cluttering its call history).
 *
 * Six stale rules, each carrying its own optional `direction` filter (null
 * = both), judged only by the call's own clocks, never created_at/updated_at
 * (except the Initiated rule below, which has no other clock available —
 * see its own note):
 *  - Ringing (inbound), unclaimed, `ringing_expires_at` older than the grace
 *      → Missed / ring_timeout
 *    Unclaimed because an inbound Ringing+claimed_by would be a
 *    contradiction — claiming atomically moves status to Answering.
 *  - Ringing (WhatsApp Outbound Calling), `ringing_expires_at` older than
 *    the grace → Failed / consumer_no_answer_outbound
 *    A SEPARATE rule from the inbound one above, not the same rule with a
 *    relaxed claimed_by condition: an outbound row's claimed_by is always
 *    the initiating agent, set at creation, so "unclaimed" cannot be reused
 *    to distinguish it — and, more importantly, the correct OUTCOME differs
 *    too (Failed/consumer_no_answer_outbound, matching exactly what
 *    RecordOutboundCallEvent::applyTerminate() already produces for this
 *    same real-world situation — no answer ever came — when a genuine
 *    terminate webhook DOES arrive; Missed/ring_timeout is inbound-worded
 *    and would be a real inconsistency here, not just cosmetically wrong).
 *  - Answering (inbound), `ringing_expires_at` older than the grace
 *    → Missed / answer_timeout (the claim never extends `ringing_expires_at`,
 *    and answer/accept refuse an expired call, so an expired Answering row
 *    can only end through here)
 *  - Answering (WhatsApp Outbound Calling — an outbound row now reaches
 *    Answering once Meta's SDP answer arrives, before the browser's own
 *    WebRTC connection is confirmed; see RecordOutboundCallEvent /
 *    ConfirmOutboundCallConnected), `ringing_expires_at` older than the
 *    grace → Failed / outbound_connect_failed. A SEPARATE rule from the
 *    inbound one above for the same reason the two Ringing rules are
 *    separate: Missed/answer_timeout is inbound-worded (the AGENT failed to
 *    answer), which does not describe an outbound row stuck here — Meta
 *    already relayed an answer, it is OUR OWN browser's WebRTC connection
 *    that never completed, matching the existing outbound-connect-failure
 *    vocabulary rather than inventing a new reason.
 *  - Connected (both directions — connected_at is populated identically by
 *    RecordInboundCall's accept() step and ConfirmOutboundCallConnected's
 *    own step, so this rule needed no change at all for outbound), `connected_at`
 *    older than `active_window_minutes` → Completed / stale_no_terminate
 *    (the real end time and duration are unknown, so ended_at and
 *    duration_seconds stay NULL for a late terminate to fill)
 *  - Initiated (WhatsApp Outbound Calling, outbound-only — inbound rows are
 *    never created Initiated), `created_at` older than
 *    `outbound_initiate_grace_seconds` → Failed / outbound_connect_failed
 *    (no wacid was ever returned/applied — InitiateOutboundCall's own
 *    connect() POST is synchronous, so this only fires for a genuinely
 *    stuck row: the process died between creating it and applying the
 *    result, or a transient failure left it unresolved. created_at is used
 *    here, not ringing_expires_at, since an Initiated row never has one —
 *    this is the one rule in this class judged by created_at rather than a
 *    dedicated expiry clock, because none exists for this state. ended_at
 *    is deliberately left NULL, same reasoning as the Connected rule: the
 *    real outcome is unknown, and a late outbound terminate/connect webhook
 *    can still fill it in via RecordOutboundCallEvent's own fill-only path)
 *
 * The grace outlasts Meta's own terminate and any in-flight answer/accept, so
 * the sweep waits for the real outcome before assuming there is none.
 *
 * Correctness is compare-and-set, not scheduling: every transition is ONE
 * conditional UPDATE that repeats the rule's WHERE clause (and the tenant), so
 * exactly one of a sweep, a second sweep, a claim, a reject or a terminate wins;
 * a loser updates zero rows and does nothing else. No lead lock, call-row lock
 * or transaction is taken (the established order is lead, then call — this takes
 * neither), and nothing external runs inside a database operation. Nothing here
 * calls Meta, and the sweep never writes meta_status / meta_stage /
 * meta_terminated_at, so a late terminate still takes RecordInboundCall's
 * fill-only path on the now-terminal row without changing its status.
 *
 * After a win, and only then: the Missed notification is dispatched, the
 * existing `call.updated` is broadcast, and both cached SDPs are dropped — each
 * best-effort and outside the transition, so none can undo or fail it.
 *
 * @phpstan-type StaleRule array{from: CallStatus, direction: ?CallDirection, to: CallStatus, reason: CallEndReason, counter: string}
 */
class FinalizeStaleLeadCalls
{
    public const int CHUNK_SIZE = 100;

    /**
     * @return array<string, int>
     */
    public function handle(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $cutoffs = [
            'grace' => $now->copy()->subSeconds(max(0, (int) config('calling.stale_grace_seconds'))),
            'connected' => $now->copy()->subMinutes((int) config('calling.active_window_minutes')),
            'initiate' => $now->copy()->subSeconds(max(0, (int) config('calling.outbound_initiate_grace_seconds'))),
        ];

        $summary = [
            'inspected' => 0,
            'missed_ring' => 0,
            'failed_ring_outbound' => 0,
            'missed_answering' => 0,
            'failed_answering_outbound' => 0,
            'completed_connected' => 0,
            'failed_initiate' => 0,
            'lost_race' => 0,
            'notifications_dispatched' => 0,
            'failures' => 0,
        ];

        $limit = max(1, (int) config('calling.reconcile_batch_limit'));

        $rules = [
            ['from' => CallStatus::Ringing, 'direction' => CallDirection::Inbound, 'to' => CallStatus::Missed, 'reason' => CallEndReason::RingTimeout, 'counter' => 'missed_ring'],
            ['from' => CallStatus::Ringing, 'direction' => CallDirection::Outbound, 'to' => CallStatus::Failed, 'reason' => CallEndReason::ConsumerNoAnswerOutbound, 'counter' => 'failed_ring_outbound'],
            ['from' => CallStatus::Answering, 'direction' => CallDirection::Inbound, 'to' => CallStatus::Missed, 'reason' => CallEndReason::AnswerTimeout, 'counter' => 'missed_answering'],
            ['from' => CallStatus::Answering, 'direction' => CallDirection::Outbound, 'to' => CallStatus::Failed, 'reason' => CallEndReason::OutboundConnectFailed, 'counter' => 'failed_answering_outbound'],
            ['from' => CallStatus::Connected, 'direction' => null, 'to' => CallStatus::Completed, 'reason' => CallEndReason::StaleNoTerminate, 'counter' => 'completed_connected'],
            ['from' => CallStatus::Initiated, 'direction' => null, 'to' => CallStatus::Failed, 'reason' => CallEndReason::OutboundConnectFailed, 'counter' => 'failed_initiate'],
        ];

        foreach ($rules as $rule) {
            if ($summary['inspected'] >= $limit) {
                break;
            }

            $this->sweep($rule, $cutoffs, $summary, $limit);
        }

        return $summary;
    }

    /**
     * The one definition of "stale" for a status — used to pick candidates AND
     * repeated in the conditional UPDATE, so a row that changed in between is
     * simply not matched.
     *
     * @param  Builder<LeadCall>  $query
     * @param  array{grace: Carbon, connected: Carbon, initiate: Carbon}  $cutoffs
     * @return Builder<LeadCall>
     */
    private function constrain(Builder $query, CallStatus $from, ?CallDirection $direction, array $cutoffs): Builder
    {
        $query->where('status', $from->value);

        if ($direction !== null) {
            $query->where('direction', $direction->value);
        }

        return match ($from) {
            // Unclaimed only for the inbound rule: claiming atomically moves
            // status to Answering, so an inbound Ringing+claimed_by is a
            // contradiction that should never occur. The outbound Ringing
            // rule (direction already filtered above) never adds this —
            // claimed_by is always the initiating agent there, set at
            // creation, so requiring it unclaimed would exclude every row.
            CallStatus::Ringing => ($direction === CallDirection::Inbound ? $query->whereNull('claimed_by') : $query)
                ->where('ringing_expires_at', '<', $cutoffs['grace']),
            CallStatus::Answering => $query->where('ringing_expires_at', '<', $cutoffs['grace']),
            CallStatus::Connected => $query->where('connected_at', '<', $cutoffs['connected']),
            default => $query->where('created_at', '<', $cutoffs['initiate']),
        };
    }

    /**
     * @param  StaleRule  $rule
     * @param  array{grace: Carbon, connected: Carbon, initiate: Carbon}  $cutoffs
     * @param  array<string, int>  $summary
     */
    private function sweep(array $rule, array $cutoffs, array &$summary, int $limit): void
    {
        $this->constrain(LeadCall::query(), $rule['from'], $rule['direction'], $cutoffs)
            ->with('leadSource')
            ->chunkById(self::CHUNK_SIZE, function ($calls) use ($rule, $cutoffs, &$summary, $limit): bool {
                foreach ($calls as $call) {
                    if ($summary['inspected'] >= $limit) {
                        return false;
                    }

                    $summary['inspected']++;

                    $this->finalize($call, $rule, $cutoffs, $summary);
                }

                return true;
            });
    }

    /**
     * @param  StaleRule  $rule
     * @param  array{grace: Carbon, connected: Carbon, initiate: Carbon}  $cutoffs
     * @param  array<string, int>  $summary
     */
    private function finalize(LeadCall $call, array $rule, array $cutoffs, array &$summary): void
    {
        try {
            $values = [
                'status' => $rule['to']->value,
                'end_reason' => $rule['reason']->value,
                'updated_at' => Carbon::now(),
            ];

            // A call that stopped ringing (inbound Ringing->Missed or
            // Answering->Missed, or outbound Ringing->Failed) ended when its
            // window closed — a known clock. Connected's and Initiated's
            // real end is unknown, so neither gets an invented one.
            if (in_array($rule['from'], [CallStatus::Ringing, CallStatus::Answering], true)) {
                $values['ended_at'] = $call->ringing_expires_at;
            }

            $won = $this->constrain(
                LeadCall::query()->where('tenant_id', $call->tenant_id)->whereKey($call->id),
                $rule['from'],
                $rule['direction'],
                $cutoffs,
            )->update($values) === 1;
        } catch (Throwable $exception) {
            $summary['failures']++;

            Log::channel('whatsapp')->error('WA CALL RECONCILE FAILED', [
                'tenant_id' => $call->tenant_id,
                'lead_call_id' => $call->id,
                'from' => $rule['from']->value,
                'exception' => $exception::class,
            ]);

            return;
        }

        if (! $won) {
            $summary['lost_race']++;

            $this->log($call, $rule, 'lost_race');

            return;
        }

        $summary[$rule['counter']]++;

        $call->forceFill(['status' => $rule['to'], 'end_reason' => $rule['reason']]);

        if (LeadCallMissedNotifier::dispatch($call)) {
            $summary['notifications_dispatched']++;
        }

        LeadCallBroadcaster::updated($call, $rule['to']);

        $this->forgetSdp($call);

        $this->log($call, $rule, 'reconciled');
    }

    /**
     * Best effort, after the transition: the cached SDPs also expire on their
     * own TTL, so a cache that is down must neither undo nor abort anything.
     */
    private function forgetSdp(LeadCall $call): void
    {
        try {
            LeadCallSdp::forget($call->leadSource, $call->external_call_id);
        } catch (Throwable) {
            // Expires on its own.
        }
    }

    /**
     * Ids and outcome only — never an SDP, token, phone number or Meta text.
     *
     * @param  StaleRule  $rule
     */
    private function log(LeadCall $call, array $rule, string $outcome): void
    {
        Log::channel('whatsapp')->info('WA CALL RECONCILE', [
            'tenant_id' => $call->tenant_id,
            'lead_call_id' => $call->id,
            'from' => $rule['from']->value,
            'to' => $rule['to']->value,
            'end_reason' => $rule['reason']->value,
            'outcome' => $outcome,
        ]);
    }
}
