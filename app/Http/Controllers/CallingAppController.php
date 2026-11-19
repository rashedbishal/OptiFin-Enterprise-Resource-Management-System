<?php

namespace App\Http\Controllers;

use App\Actions\Tenant\PairCallingAppDevice;
use App\Actions\Tenant\UnpairCallingAppDevice;
use App\Models\CallingAppDevice;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WhatsApp calling companion app — pairing and device-level endpoints. The
 * actual call actions (active calls, claim/answer/accept/reject/hangup,
 * outbound) are NOT here: the app's API routes point straight at the same
 * LeadCallController/ActiveLeadCallsController the browser softphone uses.
 */
class CallingAppController extends Controller
{
    public function pair(Request $request, PairCallingAppDevice $action): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $action->handle($validated['code'], $validated['device_name'] ?? null);

        if ($result === null) {
            return response()->json(['error' => [
                'code' => 'invalid_pairing_code',
                'message' => __('This pairing link has expired or was already used. Tap "Connect calling app" again.'),
                'retryable' => false,
            ]], 422);
        }

        return response()->json(['data' => [
            'token' => $result['token'],
            'user' => $this->userPayload($result['device']->tenantUser),
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => ['user' => $this->userPayload($this->user($request))]]);
    }

    /**
     * Liveness ping for the app's idle screen. The device middleware has
     * already refreshed last_seen_at by the time this runs; deliberately NOT
     * softphone presence — the active-calls poll owns that, same as browser.
     */
    public function heartbeat(): JsonResponse
    {
        return response()->json(['data' => ['ok' => true]]);
    }

    public function unpair(Request $request, UnpairCallingAppDevice $action): JsonResponse
    {
        $device = $request->attributes->get('callingAppDevice');

        abort_unless($device instanceof CallingAppDevice, 401);

        $action->handle($device);

        return response()->json(['data' => ['ok' => true]]);
    }

    private function user(Request $request): TenantUser
    {
        $user = $request->user();

        abort_unless($user instanceof TenantUser, 401);

        return $user;
    }

    /**
     * @return array{id: string, name: string, email: string, tenant: string|null}
     */
    private function userPayload(TenantUser $user): array
    {
        $tenant = $user->tenant;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tenant' => $tenant instanceof Tenant ? $tenant->name : null,
        ];
    }
}
