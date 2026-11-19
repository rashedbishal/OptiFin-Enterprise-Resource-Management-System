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

    /**
     * /.well-known/assetlinks.json — Android Digital Asset Links. Public and
     * unauthenticated by design (Android/Chrome fetch it anonymously). Lets
     * Chrome confirm the installed OptiFin Calling app belongs to this site,
     * which is what makes navigator.getInstalledRelatedApps() report it and
     * so lets a lead's Call button hand off to the app. Holds only public
     * facts: the package name and the signing certificate's fingerprint.
     */
    public function assetLinks(): JsonResponse
    {
        $fingerprints = config('calling.calling_app.android_sha256_fingerprints');
        $package = config('calling.calling_app.android_package');

        if (! is_array($fingerprints) || $fingerprints === [] || ! is_string($package) || $package === '') {
            return response()->json([]);
        }

        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => array_values($fingerprints),
            ],
        ]], 200, [], JSON_UNESCAPED_SLASHES)->header('Cache-Control', 'public, max-age=3600');
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

    /**
     * The app reports its Firebase Cloud Messaging token after pairing and
     * whenever Firebase rotates it — what lets an incoming call wake it.
     */
    public function pushToken(Request $request): JsonResponse
    {
        $device = $request->attributes->get('callingAppDevice');

        abort_unless($device instanceof CallingAppDevice, 401);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $device->forceFill([
            'fcm_token' => $validated['token'],
            'fcm_token_updated_at' => now(),
        ])->save();

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
