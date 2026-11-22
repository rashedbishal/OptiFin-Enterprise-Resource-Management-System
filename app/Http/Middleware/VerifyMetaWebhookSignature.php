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
 *
 * Takes one or more platform types (e.g.
 * `verify.meta.signature:facebook_messenger,facebook_lead_ads`): Messenger
 * and Lead Ads share one webhook URL and are signed with the same Meta App
 * Secret, but a tenant might configure either one without the other — the
 * first configured source with an app_secret set wins.
 */
class VerifyMetaWebhookSignature
{
    public function handle(Request $request, Closure $next, string ...$platforms): Response
    {
        $tenantCode = (string) $request->route('tenant_code');

        $tenant = Tenant::where('tenant_code', $tenantCode)
            ->where('status', TenantStatus::Active)
            ->first();

        $source = null;

        if ($tenant !== null) {
            $webhookToken = $request->route('webhook_token');

            $source = (is_string($webhookToken) && $webhookToken !== '' ? $this->findTokenSourceWithAppSecret($tenant->id, $webhookToken, array_values($platforms)) : null)
                ?? $this->findSourceWithAppSecret($tenant->id, array_values($platforms));
        }

        if ($source === null) {
            return $next($request);
        }

        $appSecret = (string) $source->config['app_secret'];

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! str_starts_with($signature, 'sha256=') || ! hash_equals($expected, $signature)) {
            $platformList = implode('/', $platforms);
            $reason = "Signature mismatch on the {$platformList} webhook — the App Secret doesn't match what Meta signed this request with.";

            Log::warning("Rejected {$platformList} webhook for tenant [{$tenantCode}]: signature mismatch.");

            // No admin browser session is attached to this request — Meta's
            // server sent it — so there's no live toast to show right now.
            // Recorded here instead and surfaced by
            // LeadSources::flashSignatureRejections() the next time an
            // admin opens this source's Lead Sources page.
            // Throttled to one write per minute: every rejected request is
            // unauthenticated, so an unthrottled write per request would let
            // anyone turn forged POSTs into database write load.
            if ($source->last_signature_rejection_at === null || $source->last_signature_rejection_at->lt(now()->subMinute())) {
                $source->update([
                    'last_signature_rejection_at' => now(),
                    'last_signature_rejection_reason' => $reason,
                ]);
            }

            abort(403);
        }

        return $next($request);
    }

    /**
     * On the token-bearing route, the token names the exact source the
     * request is for. Use that source's own App Secret when it has one, so a
     * tenant with more than one source of the same type is verified against
     * the right Meta App instead of whichever source happens to come first.
     * Returns null (falling back to findSourceWithAppSecret()) when the token
     * matches nothing or that source has no secret set.
     *
     * @param  array<int, string>  $platforms
     */
    private function findTokenSourceWithAppSecret(string $tenantId, string $webhookToken, array $platforms): ?LeadSource
    {
        $types = array_map(fn (string $platform): LeadSourceType => LeadSourceType::from($platform), $platforms);

        $source = LeadSource::query()
            ->where('tenant_id', $tenantId)
            ->where('webhook_token_hash', hash('sha256', $webhookToken))
            ->whereIn('type', $types)
            ->where('is_active', true)
            ->first();

        $appSecret = $source?->config['app_secret'] ?? null;

        return is_string($appSecret) && $appSecret !== '' ? $source : null;
    }

    /**
     * @param  array<int, string>  $platforms
     */
    private function findSourceWithAppSecret(string $tenantId, array $platforms): ?LeadSource
    {
        foreach ($platforms as $platform) {
            $type = LeadSourceType::from($platform);

            $source = LeadSource::where('tenant_id', $tenantId)->where('type', $type)->where('is_active', true)->first();
            $appSecret = $source?->config['app_secret'] ?? null;

            if (is_string($appSecret) && $appSecret !== '') {
                return $source;
            }
        }

        return null;
    }
}
