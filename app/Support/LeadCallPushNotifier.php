<?php

namespace App\Support;

use App\Enums\CallStatus;
use App\Jobs\SendIncomingCallPushNotification;
use App\Models\LeadCall;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp inbound calling — the one way a fresh Ringing call is handed to
 * Web Push. Same shape as LeadCallMissedNotifier: callers invoke it only
 * AFTER the row has committed and only for the request that put it into
 * Ringing (RecordInboundCall::connect(), right alongside
 * LeadCallBroadcaster::updated() — never for a status it merely passes
 * through), and a dispatch failure is swallowed and logged by id only, so a
 * webhook must still return 200 regardless of the queue's health.
 */
final class LeadCallPushNotifier
{
    public static function ring(LeadCall $call): bool
    {
        if ($call->status !== CallStatus::Ringing) {
            return false;
        }

        try {
            SendIncomingCallPushNotification::dispatch($call->tenant_id, $call->id);

            return true;
        } catch (Throwable $exception) {
            Log::channel('whatsapp')->warning('WA CALL PUSH NOTIFICATION DISPATCH FAILED', [
                'tenant_id' => $call->tenant_id,
                'lead_call_id' => $call->id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
