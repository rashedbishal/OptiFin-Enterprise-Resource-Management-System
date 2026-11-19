<?php

namespace App\Support;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Events\LeadCallUpdated;
use App\Jobs\SendCallingAppCallPush;
use App\Models\LeadCall;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp inbound calling — the one way a call transition is announced.
 *
 * Realtime is a convenience layered on top of state that is already committed
 * (the softphone re-reads its active calls on every event and on a safety
 * poll), so announcing must never be able to fail the thing that caused it: a
 * webhook must still return 200 and an agent action must still return its
 * result when Reverb, the queue or the broadcaster is unavailable. Every
 * failure is swallowed here and logged by id only.
 *
 * Callers invoke it only AFTER the transition it announces has succeeded and
 * committed; a call with nobody routed to it announces nothing.
 */
final class LeadCallBroadcaster
{
    public static function updated(LeadCall $call, CallStatus $status): void
    {
        $recipients = array_values(array_filter($call->routed_user_ids ?? [], 'is_string'));

        if ($recipients === []) {
            return;
        }

        try {
            event(new LeadCallUpdated($call->id, $status->value, $recipients));
        } catch (Throwable $exception) {
            Log::channel('whatsapp')->warning('WA CALL BROADCAST FAILED', [
                'tenant_id' => $call->tenant_id,
                'lead_call_id' => $call->id,
                'status' => $status->value,
                'exception' => $exception::class,
            ]);
        }

        self::stopAppRinging($call, $status);
    }

    /**
     * Calling companion app: an inbound call that just left Ringing (claimed,
     * declined, missed, failed, ended) must stop ringing on every routed
     * phone — the browser hears this broadcast, a closed app needs a push.
     * Connected is skipped (it always follows Answering, which already sent
     * it); a dispatch failure is logged and never affects the caller.
     */
    private static function stopAppRinging(LeadCall $call, CallStatus $status): void
    {
        if ($call->direction !== CallDirection::Inbound || in_array($status, [CallStatus::Ringing, CallStatus::Connected, CallStatus::Initiated], true)) {
            return;
        }

        try {
            SendCallingAppCallPush::dispatch($call->tenant_id, $call->id, SendCallingAppCallPush::ENDED);
        } catch (Throwable $exception) {
            Log::channel('whatsapp')->warning('WA CALL APP STOP PUSH DISPATCH FAILED', [
                'tenant_id' => $call->tenant_id,
                'lead_call_id' => $call->id,
                'status' => $status->value,
                'exception' => $exception::class,
            ]);
        }
    }
}
