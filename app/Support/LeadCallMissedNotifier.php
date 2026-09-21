<?php

namespace App\Support;

use App\Enums\CallStatus;
use App\Jobs\SendMissedCallNotification;
use App\Models\LeadCall;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp inbound calling — the one way a Missed call is handed to the bell.
 *
 * Callers invoke it only AFTER the transition into Missed has committed and only
 * for the request that made that transition (the stale-call sweep's winning
 * conditional UPDATE, a terminate that changed the status, or the creation of an
 * already-Missed row), so a repeat, a lost race or a fill-only terminate never
 * reaches it. Anything but Missed (Failed, Rejected, …) is refused here too.
 *
 * Like LeadCallBroadcaster it must never be able to fail the thing that caused
 * it — a webhook must still return 200 and the sweep must still finish when the
 * queue is unavailable — so a dispatch failure is swallowed and logged by id
 * only (the state has committed; the notification is then simply not sent).
 */
final class LeadCallMissedNotifier
{
    public static function dispatch(LeadCall $call): bool
    {
        if ($call->status !== CallStatus::Missed) {
            return false;
        }

        try {
            SendMissedCallNotification::dispatch($call->tenant_id, $call->id);

            return true;
        } catch (Throwable $exception) {
            Log::channel('whatsapp')->warning('WA CALL MISSED NOTIFICATION DISPATCH FAILED', [
                'tenant_id' => $call->tenant_id,
                'lead_call_id' => $call->id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
