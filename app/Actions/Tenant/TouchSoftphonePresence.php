<?php

namespace App\Actions\Tenant;

use App\Models\TenantUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WhatsApp Call Backup / Overflow Receiver — the single writer of
 * tenant_users.softphone_last_seen_at. Called only from
 * App\Http\Controllers\ActiveLeadCallsController, so the timestamp is
 * refreshed as a side effect of the softphone's own already-existing GET
 * calls/active poll rather than a new endpoint — the acting user is always
 * $request->user(), resolved server-side; no id is ever accepted from the
 * browser, and a plain query-builder UPDATE keyed on that user's own primary
 * key touches exactly one row, tenant boundary included for defense in depth
 * even though the id alone already can't cross tenants.
 *
 * Read-only from the caller's point of view: a failure here must never fail
 * the active-calls read it rides along with (same "never fail the thing that
 * caused it" discipline as LeadCallBroadcaster/rememberCall() elsewhere in
 * this module), so it is swallowed and logged by id only.
 */
class TouchSoftphonePresence
{
    public function handle(TenantUser $user): void
    {
        try {
            TenantUser::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('id', $user->id)
                ->update(['softphone_last_seen_at' => Carbon::now()]);
        } catch (Throwable $exception) {
            Log::channel('whatsapp')->warning('WA SOFTPHONE PRESENCE TOUCH FAILED', [
                'tenant_id' => $user->tenant_id,
                'tenant_user_id' => $user->id,
                'exception' => $exception::class,
            ]);
        }
    }
}
