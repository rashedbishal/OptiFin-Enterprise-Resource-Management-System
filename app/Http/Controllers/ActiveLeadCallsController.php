<?php

namespace App\Http\Controllers;

use App\Actions\Tenant\ListActiveLeadCalls;
use App\Actions\Tenant\TouchSoftphonePresence;
use App\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WhatsApp inbound calling — GET lead-management/calls/active, the softphone's
 * recovery read. All filtering and authorization lives in ListActiveLeadCalls;
 * this only renders it. Never cacheable, and the body never carries an SDP.
 *
 * WhatsApp Call Backup / Overflow Receiver — this is also the softphone's own
 * existing heartbeat (it polls here at least every
 * `calling.softphone_heartbeat_seconds`, more often once degraded — see
 * SoftphonePresence's own docblock), so TouchSoftphonePresence rides along
 * here rather than adding a second endpoint/timer. It never affects the
 * response.
 */
class ActiveLeadCallsController extends Controller
{
    public function __invoke(Request $request, ListActiveLeadCalls $action, TouchSoftphonePresence $presence): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof TenantUser, 401);

        $presence->handle($user);

        return response()
            ->json(['data' => ['calls' => $action->handle($user)]])
            ->header('Cache-Control', 'no-store, private');
    }
}
