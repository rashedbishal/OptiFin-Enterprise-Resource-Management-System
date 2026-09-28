<?php

namespace App\Actions\Tenant;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\LeadCall;
use App\Models\LeadSource;
use App\Support\WhatsAppCallStatusEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * WhatsApp Outbound Calling — the single writer that applies a
 * WhatsAppCallStatusEvent (Meta's mid-call RINGING/ACCEPTED/REJECTED status,
 * delivered on the SAME `statuses[]` array key message-delivery receipts
 * use — see LeadWebhookController::whatsapp(), which routes a `type: "call"`
 * item here before it ever reaches message-status reconciliation) to an
 * existing outbound LeadCall row. Never creates a row, and every lookup is
 * scoped by `direction = outbound`, matching RecordOutboundCallEvent's exact
 * discipline — this can never touch an inbound row even if a wacid were
 * somehow reused.
 *
 * ACCEPTED is Meta's own authoritative "the consumer answered" signal — the
 * earlier `connect` webhook's SDP answer (RecordOutboundCallEvent) fires
 * before the consumer's device even rings, and is not evidence of pickup.
 * This action ONLY records that fact (`consumer_accepted_at`); it
 * deliberately never sets Connected/connected_at itself and never
 * broadcasts. ConfirmOutboundCallConnected remains the ONLY writer of
 * Connected/connected_at, and now requires BOTH this signal AND the
 * browser's own real WebRTC/ICE confirmation before it will fire.
 */
class RecordOutboundCallStatusEvent
{
    public const string OUTCOME_UNKNOWN_CALL = 'unknown_call';

    public const string OUTCOME_RINGING_OBSERVED = 'ringing_observed';

    public const string OUTCOME_ACCEPTED = 'accepted';

    public const string OUTCOME_ACCEPTED_NOOP = 'accepted_noop';

    public const string OUTCOME_REJECTED_OBSERVED = 'rejected_observed';

    public function handle(WhatsAppCallStatusEvent $event, LeadSource $source): string
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

        if ($event->isRinging()) {
            // Purely informational: our own Ringing/Answering states already
            // cover the UI's needs for this window, and CallStatus has no
            // dedicated case for "the consumer's device is now audibly
            // ringing" — never a status/connected_at/timer/ringback change.
            return self::OUTCOME_RINGING_OBSERVED;
        }

        if ($event->isAccepted()) {
            return $this->applyAccepted($call);
        }

        return $this->applyRejected($call, $source);
    }

    /**
     * One conditional UPDATE, fill-only in spirit: only ever moves
     * consumer_accepted_at from NULL to now(), only while still Answering (a
     * call that has moved on — already Connected, or ended — has nothing
     * left for this signal to unblock). Idempotent: a duplicate ACCEPTED
     * delivery simply finds nothing left to do.
     */
    private function applyAccepted(LeadCall $call): string
    {
        $applied = LeadCall::query()
            ->where('tenant_id', $call->tenant_id)
            ->whereKey($call->id)
            ->where('status', CallStatus::Answering->value)
            ->whereNull('consumer_accepted_at')
            ->update([
                'consumer_accepted_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]) === 1;

        return $applied ? self::OUTCOME_ACCEPTED : self::OUTCOME_ACCEPTED_NOOP;
    }

    /**
     * Never a status change here — the terminate webhook Meta always sends
     * next remains the sole terminal-transition authority
     * (RecordOutboundCallEvent::applyTerminate()). This only leaves a
     * short-lived, best-effort marker so that terminate can pick the
     * accurate CallEndReason::ConsumerDeclinedOutbound instead of the
     * generic OutboundConnectFailed it would otherwise fall back to. The
     * marker expiring or failing to write changes nothing structural — worst
     * case, terminate simply falls back to its existing generic reason.
     */
    private function applyRejected(LeadCall $call, LeadSource $source): string
    {
        if ($call->external_call_id !== null) {
            try {
                Cache::put(
                    self::declineCacheKey($source, $call->external_call_id),
                    true,
                    Carbon::now()->addSeconds((int) config('calling.ring_ttl_seconds')),
                );
            } catch (Throwable) {
                // Best-effort only — see the docblock above.
            }
        }

        return self::OUTCOME_REJECTED_OBSERVED;
    }

    public static function declineCacheKey(LeadSource $source, ?string $callId): string
    {
        return "wa-outbound-declined:{$source->tenant_id}:{$source->id}:{$callId}";
    }
}
