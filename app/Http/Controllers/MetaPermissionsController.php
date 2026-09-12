<?php

namespace App\Http\Controllers;

use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/**
 * Inspects a lead source's stored token via Meta's `debug_token` endpoint
 * and reports which of the permissions this app actually needs are granted.
 * Deliberately platform-admin-authenticated and tenant-scoped, not a public
 * `/api/...` route — token scope information is sensitive, and the `/api`
 * prefix is otherwise exclusively unauthenticated webhook endpoints.
 */
class MetaPermissionsController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_SCOPES = [
        'pages_messaging',
        'instagram_manage_messages',
        // Feature #15 — the WhatsApp Cloud API's own equivalent permission
        // scope, governing both receiving AND sending; confirmed via the
        // Final Architecture Validation Report's live documentation
        // check — WhatsApp is checkable through this exact same
        // debug_token mechanism, contrary to an earlier draft assumption
        // that it had no OAuth-scope-equivalent model.
        'whatsapp_business_messaging',
        'leads_retrieval',
        'pages_manage_metadata',
    ];

    public function show(Tenant $tenant, LeadSource $leadSource): JsonResponse
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $tenant);

        abort_unless($leadSource->tenant_id === $tenant->id, 404);

        $accessToken = $leadSource->config['page_access_token'] ?? $leadSource->config['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return response()->json(['error' => 'No access token is configured on this lead source.'], 422);
        }

        $response = Http::timeout(10)->get('https://graph.facebook.com/debug_token', [
            'input_token' => $accessToken,
            'access_token' => $accessToken,
        ]);

        if (! $response->successful()) {
            return response()->json([
                'error' => $response->json('error.message') ?? 'Could not inspect this token.',
                'token' => $this->maskToken($accessToken),
            ], 502);
        }

        $scopes = $response->json('data.scopes') ?? [];

        return response()->json([
            'lead_source_id' => $leadSource->id,
            'lead_source_type' => $leadSource->type->value,
            'token' => $this->maskToken($accessToken),
            'app_id' => $response->json('data.app_id'),
            'is_valid' => $response->json('data.is_valid'),
            'expires_at' => $response->json('data.expires_at'),
            'granted_scopes' => $scopes,
            'required_scopes' => collect(self::REQUIRED_SCOPES)->mapWithKeys(fn (string $scope): array => [
                $scope => is_array($scopes) && in_array($scope, $scopes, true),
            ]),
        ]);
    }

    /**
     * Reveals only the last 10 characters — enough to tell tokens apart in
     * the response without exposing anything usable.
     */
    private function maskToken(string $token): string
    {
        $tail = substr($token, -10);

        return str_repeat('•', max(0, strlen($token) - strlen($tail))).$tail;
    }
}
