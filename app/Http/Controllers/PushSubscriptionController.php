<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * WhatsApp inbound calling — lets the softphone register/unregister this
 * browser's Push subscription for the logged-in TenantUser, so
 * IncomingWhatsAppCallNotification can reach it even when the tab is closed.
 * Always acts on the authenticated user only — never a client-suppliable id,
 * same trust boundary as every other softphone endpoint.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        Auth::user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
        );

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        Auth::user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['status' => 'ok']);
    }
}
