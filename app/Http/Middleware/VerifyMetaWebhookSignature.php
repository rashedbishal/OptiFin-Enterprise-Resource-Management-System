<?php

namespace App\Http\Middleware;

use App\Enums\LeadSourceType;
use App\Enums\TenantStatus;
use App\Models\LeadSource;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Meta's `X-Hub-Signature-256` HMAC on an incoming webhook POST,
 * keyed by the App Secret of the *tenant's own* Meta App — each tenant
 * connects their own Facebook/Instagram/WhatsApp app, so there is no single
 * shared secret to check against, unlike the verify_token used for the
 * GET challenge (which is why this can't just extend that check).
 *
 * Deliberately opt-in per source: a source configured before this existed
 * (or one whose admin hasn't filled in the App Secret field yet) has no
 * `app_secret`, and failing those closed by default would silently stop
 * every already-working webhook until someone noticed and backfilled it.
 * Verification only activates once a tenant sets an App Secret.
 */
class VerifyMetaWebhookSignature
{
    public function handle(Request $request, Closure $next, string $platform): Response
    {
        $type = LeadSourceType::from($platform);
        $tenantCode = (string) $request->route('tenant_code');

        $tenant = Tenant::where('tenant_code', $tenantCode)
            ->where('status', TenantStatus::Active)
            ->first();

        $source = $tenant
            ? LeadSource::where('tenant_id', $tenant->id)->where('type', $type)->where('is_active', true)->first()
            : null;

        $appSecret = $source?->config['app_secret'] ?? null;

        if (! is_string($appSecret) || $appSecret === '') {
            return $next($request);
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! str_starts_with($signature, 'sha256=') || ! hash_equals($expected, $signature)) {
            Log::warning("Rejected {$platform} webhook for tenant [{$tenantCode}]: signature mismatch.");

            abort(403);
        }

        return $next($request);
    }
}
