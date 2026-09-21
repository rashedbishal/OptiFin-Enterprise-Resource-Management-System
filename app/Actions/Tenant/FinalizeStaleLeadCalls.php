<?php

namespace App\Actions\Tenant;

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
 * Three stale rules, each judged only by the call's own clocks, never
 * created_at/updated_at:
 *  - Ringing, unclaimed, `ringing_expires_at` older than the grace   → Missed / ring_timeout
 *  - Answering, `ringing_expires_at` older than the grace            → Missed / answer_timeout
 *    (the claim never extends `ringing_expires_at`, and answer/accept refuse an
 *    expired call, so an expired Answering row can only end through here)
 *  - Connected, `connected_at` older than `active_window_minutes`    → Completed / stale_no_terminate
 *    (the real end time and duration are unknown, so ended_at and
 *    duration_seconds stay NULL for a late terminate to fill)
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
 * @phpstan-type StaleRule array{from: CallStatus, to: CallStatus, reason: CallEndReason, counter: string}
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
        ];

        $summary = [
            'inspected' => 0,
            'missed_ring' => 0,
            'missed_answering' => 0,
            'completed_connected' => 0,
            'lost_race' => 0,
            'notifications_dispatched' => 0,
            'failures' => 0,
        ];

        $limit = max(1, (int) config('calling.reconcile_batch_limit'));

        $rules = [
            ['from' => CallStatus::Ringing, 'to' => CallStatus::Missed, 'reason' => CallEndReason::RingTimeout, 'counter' => 'missed_ring'],
            ['from' => CallStatus::Answering, 'to' => CallStatus::Missed, 'reason' => CallEndReason::AnswerTimeout, 'counter' => 'missed_answering'],
            ['from' => CallStatus::Connected, 'to' => CallStatus::Completed, 'reason' => CallEndReason::StaleNoTerminate, 'counter' => 'completed_connected'],
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
     * @param  array{grace: Carbon, connected: Carbon}  $cutoffs
     * @return Builder<LeadCall>
     */
    private function constrain(Builder $query, CallStatus $from, array $cutoffs): Builder
    {
        return match ($from) {
            CallStatus::Ringing => $query
                ->where('status', CallStatus::Ringing->value)
                ->whereNull('claimed_by')
                ->where('ringing_expires_at', '<', $cutoffs['grace']),
            CallStatus::Answering => $query
                ->where('status', CallStatus::Answering->value)
                ->where('ringing_expires_at', '<', $cutoffs['grace']),
            default => $query
                ->where('status', CallStatus::Connected->value)
                ->where('connected_at', '<', $cutoffs['connected']),
        };
    }

    /**
     * @param  StaleRule  $rule
     * @param  array{grace: Carbon, connected: Carbon}  $cutoffs
     * @param  array<string, int>  $summary
     */
    private function sweep(array $rule, array $cutoffs, array &$summary, int $limit): void
    {
        $this->constrain(LeadCall::query(), $rule['from'], $cutoffs)
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
     * @param  array{grace: Carbon, connected: Carbon}  $cutoffs
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

            // A call that stopped ringing ended when its window closed; a
            // Connected call's real end is unknown, so it gets no invented one.
            if ($rule['to'] === CallStatus::Missed) {
                $values['ended_at'] = $call->ringing_expires_at;
            }

            $won = $this->constrain(
                LeadCall::query()->where('tenant_id', $call->tenant_id)->whereKey($call->id),
                $rule['from'],
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
