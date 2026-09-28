<?php

namespace App\Actions\Tenant;

use App\Enums\CallDirection;
use App\Enums\CallEndReason;
use App\Enums\CallStatus;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Support\LeadCallBroadcaster;
use App\Support\LeadCallSdp;
use App\Support\WhatsAppOutboundCallEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * WhatsApp Outbound Calling — the single writer that applies a
 * WhatsAppOutboundCallEvent (a `direction: BUSINESS_INITIATED` webhook item)
 * to an existing outbound LeadCall row. Never creates a row — an outbound
 * call is only ever created by InitiateOutboundCall — and never touches an
 * inbound one: every lookup is scoped by `direction = outbound` in addition
 * to tenant/source, so this can never collide with RecordInboundCall's own
 * writes even if a wacid were somehow reused.
 *
 * `connect` here carries Meta's ANSWER (the mirror of RecordInboundCall's
 * `connect`, which carries the caller's offer): validated/normalized with
 * the same LeadCallSdp::normalizeAnswer() the inbound answer step already
 * uses (direction-agnostic by construction), cached encrypted, and applied
 * with one conditional UPDATE Ringing -> Answering — never Ringing ->
 * Connected directly. Meta's public documentation does not confirm that an
 * SDP answer arriving means the consumer physically answered, and a real
 * browser reproduction showed the answer can arrive while the consumer's
 * phone is still audibly ringing — so this webhook alone never sets
 * `connected_at` or broadcasts Connected. Only ConfirmOutboundCallConnected,
 * driven by the browser's own real WebRTC connection, completes
 * Answering -> Connected. A duplicate or out-of-order delivery (call no
 * longer Ringing) is a no-op, never re-applied, matching
 * RecordInboundCall::duplicateConnect()'s exact "never modifies the row"
 * discipline.
 *
 * `terminate` reuses RecordInboundCall's own shape as closely as its
 * different lifecycle allows: a fill-only update for an already-terminal
 * row, one conditional UPDATE otherwise, and — since the exact Meta status/
 * error vocabulary for "declined" vs "no answer" was not confirmed in the
 * verified contract — a deliberately conservative two-way split
 * (hasErrors -> Failed/OutboundConnectFailed, otherwise -> the most
 * accurate reason available for that state) rather than a finer-grained
 * distinction this build step cannot actually verify.
 */
class RecordOutboundCallEvent
{
    public const string OUTCOME_ANSWERING = 'answering';

    public const string OUTCOME_INVALID_ANSWER = 'invalid_answer';

    public const string OUTCOME_ANSWER_CACHE_FAILED = 'answer_cache_failed';

    public const string OUTCOME_UNKNOWN_CALL = 'unknown_call';

    public const string OUTCOME_NOOP = 'noop';

    public const string OUTCOME_TERMINATE_APPLIED = 'terminate_applied';

    public const string OUTCOME_TERMINATE_FILLED = 'terminate_filled';

    public const string OUTCOME_TERMINATE_NOOP = 'terminate_noop';

    public function handle(WhatsAppOutboundCallEvent $event, LeadSource $source): string
    {
        $call = LeadCall::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('lead_source_id', $source->id)
            ->where('direction', CallDirection::Outbound->value)
            ->where('external_call_id', $event->callId)
            ->first();

        if ($call === null) {
            return self::OUTCOME_UNKNOWN_CALL;
        }

        return $event->isConnect() ? $this->applyAnswer($call, $source, $event) : $this->applyTerminate($call, $source, $event);
    }

    private function applyAnswer(LeadCall $call, LeadSource $source, WhatsAppOutboundCallEvent $event): string
    {
        if ($call->status !== CallStatus::Ringing) {
            return self::OUTCOME_NOOP;
        }

        $normalized = LeadCallSdp::normalizeAnswer($event->sdp);

        if ($normalized === null) {
            return self::OUTCOME_INVALID_ANSWER;
        }

        if (! LeadCallSdp::storeAnswer($source, $call->external_call_id, $normalized)) {
            return self::OUTCOME_ANSWER_CACHE_FAILED;
        }

        $applied = LeadCall::query()
            ->where('tenant_id', $call->tenant_id)
            ->whereKey($call->id)
            ->where('status', CallStatus::Ringing->value)
            ->update([
                'status' => CallStatus::Answering->value,
                'updated_at' => Carbon::now(),
            ]) === 1;

        if (! $applied) {
            return self::OUTCOME_NOOP;
        }

        LeadCallBroadcaster::updated($call->fresh(), CallStatus::Answering);

        return self::OUTCOME_ANSWERING;
    }

    private function applyTerminate(LeadCall $call, LeadSource $source, WhatsAppOutboundCallEvent $event): string
    {
        $endedAt = $event->endTime !== null ? Carbon::createFromTimestamp($event->endTime) : Carbon::now();

        if ($call->status->isTerminal()) {
            return $this->fillOnly($call, $event, $endedAt);
        }

        [$next, $reason] = match ($call->status) {
            CallStatus::Ringing => [CallStatus::Failed, $event->hasErrors ? CallEndReason::OutboundConnectFailed : CallEndReason::ConsumerNoAnswerOutbound],
            // Meta answered (Ringing -> Answering already happened) but the
            // browser's own WebRTC connection never completed before this
            // terminate arrived — an outbound-connect failure either way,
            // UNLESS Meta's own `REJECTED` call-status webhook
            // (RecordOutboundCallStatusEvent) already told us the consumer
            // explicitly declined, in which case that accurate reason is
            // used instead of the generic connect-failure one.
            CallStatus::Answering => [
                CallStatus::Failed,
                $this->consumerDeclined($source, $call) ? CallEndReason::ConsumerDeclinedOutbound : CallEndReason::OutboundConnectFailed,
            ],
            CallStatus::Connected => [CallStatus::Completed, $event->hasErrors ? CallEndReason::OutboundConnectFailed : CallEndReason::RemoteTerminated],
            default => [$call->status, null],
        };

        if ($reason === null || ! $call->status->canTransitionTo($next)) {
            return self::OUTCOME_TERMINATE_NOOP;
        }

        $applied = LeadCall::query()
            ->where('tenant_id', $call->tenant_id)
            ->whereKey($call->id)
            ->where('status', $call->status->value)
            ->update([
                'status' => $next->value,
                'end_reason' => $reason->value,
                'meta_status' => $event->rawMetaStatus(),
                'meta_terminated_at' => Carbon::now(),
                'ended_at' => $endedAt,
                'duration_seconds' => $event->duration,
                'updated_at' => Carbon::now(),
            ]) === 1;

        if (! $applied) {
            return self::OUTCOME_TERMINATE_NOOP;
        }

        LeadCallBroadcaster::updated($call->fresh(), $next);

        return self::OUTCOME_TERMINATE_APPLIED;
    }

    /**
     * Best-effort, consumed at most once: a short-lived marker
     * RecordOutboundCallStatusEvent leaves when Meta's REJECTED call-status
     * webhook already told us the consumer explicitly declined. Absent
     * (never arrived, expired, or a cache outage) simply means the generic
     * connect-failure reason is used instead — never a structural
     * difference in the transition itself.
     */
    private function consumerDeclined(LeadSource $source, LeadCall $call): bool
    {
        if ($call->external_call_id === null) {
            return false;
        }

        try {
            return (bool) Cache::pull(RecordOutboundCallStatusEvent::declineCacheKey($source, $call->external_call_id));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A concurrent second terminate delivery (a real Meta redelivery
     * pattern) can read this same already-terminal row before either
     * writer's UPDATE commits, so the in-memory null-check above is not by
     * itself enough to prevent a last-write-wins clobber — each column this
     * writes is additionally guarded by its own `whereNull()` in the UPDATE
     * itself (a compare-and-set, mirroring every other write in this
     * module), so a writer that loses the race affects zero rows and
     * reports a true no-op instead of silently overwriting whatever the
     * winner already wrote.
     */
    private function fillOnly(LeadCall $call, WhatsAppOutboundCallEvent $event, Carbon $endedAt): string
    {
        $updates = [];

        if ($call->meta_status === null && $event->rawMetaStatus() !== null) {
            $updates['meta_status'] = $event->rawMetaStatus();
        }

        if ($call->ended_at === null) {
            $updates['ended_at'] = $endedAt;
        }

        if ($call->duration_seconds === null && $event->duration !== null) {
            $updates['duration_seconds'] = $event->duration;
        }

        if ($updates === []) {
            return self::OUTCOME_TERMINATE_NOOP;
        }

        $query = LeadCall::query()->where('tenant_id', $call->tenant_id)->whereKey($call->id);

        foreach (array_keys($updates) as $column) {
            $query->whereNull($column);
        }

        $applied = $query->update($updates + ['updated_at' => Carbon::now()]) === 1;

        return $applied ? self::OUTCOME_TERMINATE_FILLED : self::OUTCOME_TERMINATE_NOOP;
    }
}
