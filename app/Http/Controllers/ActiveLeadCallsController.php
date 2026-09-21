<?php

namespace App\Http\Controllers;

use App\Actions\Tenant\ListActiveLeadCalls;
use App\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WhatsApp inbound calling — GET lead-management/calls/active, the softphone's
 * recovery read. All filtering and authorization lives in ListActiveLeadCalls;
 * this only renders it. Never cacheable, and the body never carries an SDP.
 */
class ActiveLeadCallsController extends Controller
{
    public function __invoke(Request $request, ListActiveLeadCalls $action): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof TenantUser, 401);

        return response()
            ->json(['data' => ['calls' => $action->handle($user)]])
            ->header('Cache-Control', 'no-store, private');
    }
}
