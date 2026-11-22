<?php

namespace App\Services;

use App\Enums\LeadSourceType;
use App\Enums\TokenCheckStatus;
use App\Models\LeadSource;
use App\Models\LeadSourceTokenCheck;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The daily, read-only Meta token check behind Admin → Monitoring →
 * Integrations (`integrations:check-meta-tokens`). One call per source:
 *
 *  - Facebook Messenger / Lead Ads / WhatsApp / Instagram (Facebook login):
 *    Graph API debug_token with the token inspecting itself — the same
 *    approach MetaPermissionsController already uses — giving valid/invalid
 *    and the expiry date (0 = never expires).
 *  - Instagram (Instagram login, graph.instagram.com): no debug_token there,
 *    so a plain /me call tells valid/invalid only; expiry stays unknown.
 *
 * Never changes a token and never logs or stores it — only the outcome.
 */
class MetaTokenChecker
{
    /**
     * @var list<LeadSourceType>
     */
    public const META_TYPES = [
        LeadSourceType::FacebookMessenger,
        LeadSourceType::FacebookLeadAds,
        LeadSourceType::Whatsapp,
        LeadSourceType::Instagram,
    ];

    public static function isMetaSource(LeadSource $source): bool
    {
        return in_array($source->type, self::META_TYPES, true);
    }

    public static function tokenOf(LeadSource $source): ?string
    {
        $config = $source->config ?? [];
        $token = $config['page_access_token'] ?? $config['access_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function check(LeadSource $source): LeadSourceTokenCheck
    {
        $token = self::tokenOf($source);

        if ($token === null) {
            return $this->record($source, TokenCheckStatus::Missing);
        }

        try {
            return ($source->config['auth_mode'] ?? 'facebook') === 'instagram'
                ? $this->checkInstagramLogin($source, $token)
                : $this->checkWithDebugToken($source, $token);
        } catch (ConnectionException) {
            return $this->record($source, TokenCheckStatus::Unreachable, error: __('Meta could not be reached.'));
        }
    }

    private function checkWithDebugToken(LeadSource $source, string $token): LeadSourceTokenCheck
    {
        $response = Http::timeout(10)->get('https://graph.facebook.com/debug_token', [
            'input_token' => $token,
            'access_token' => $token,
        ]);

        if ($response->serverError()) {
            return $this->record($source, TokenCheckStatus::Unreachable, error: $this->metaError($response->json('error.message')));
        }

        if ($response->failed() || $response->json('data.is_valid') !== true) {
            return $this->record($source, TokenCheckStatus::Invalid, error: $this->metaError($response->json('error.message') ?? $response->json('data.error.message')));
        }

        $expiresAt = (int) $response->json('data.expires_at', 0);

        return $this->record(
            $source,
            TokenCheckStatus::Valid,
            expiresAt: $expiresAt > 0 ? CarbonImmutable::createFromTimestamp($expiresAt) : null,
            expiryKnown: true,
        );
    }

    private function checkInstagramLogin(LeadSource $source, string $token): LeadSourceTokenCheck
    {
        $response = Http::timeout(10)->get('https://graph.instagram.com/me', [
            'fields' => 'id',
            'access_token' => $token,
        ]);

        if ($response->serverError()) {
            return $this->record($source, TokenCheckStatus::Unreachable, error: $this->metaError($response->json('error.message')));
        }

        return $response->successful()
            ? $this->record($source, TokenCheckStatus::Valid)
            : $this->record($source, TokenCheckStatus::Invalid, error: $this->metaError($response->json('error.message')));
    }

    private function record(LeadSource $source, TokenCheckStatus $status, ?CarbonImmutable $expiresAt = null, bool $expiryKnown = false, ?string $error = null): LeadSourceTokenCheck
    {
        return LeadSourceTokenCheck::query()->updateOrCreate(
            ['lead_source_id' => $source->id],
            [
                'status' => $status,
                'expires_at' => $expiresAt,
                'expiry_known' => $expiryKnown,
                'error' => $error,
                'checked_at' => now(),
            ],
        );
    }

    private function metaError(mixed $message): ?string
    {
        return is_string($message) && $message !== '' ? Str::limit($message, 480) : null;
    }
}
