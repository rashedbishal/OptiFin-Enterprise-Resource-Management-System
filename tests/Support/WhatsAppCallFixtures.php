<?php

namespace Tests\Support;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Shared builders for the WhatsApp inbound-calling tests: a Cloud API `calls`
 * webhook payload shaped like Meta's documented examples, and a WhatsApp lead
 * source with calling enabled and an app secret (calls are only processed for
 * a source that has one). The SDP carries a sentinel string so a test can prove
 * it never reaches a database row or a log line.
 */
final class WhatsAppCallFixtures
{
    public const string PHONE_NUMBER_ID = '436666719526789';

    public const string BUSINESS_NUMBER = '16315553601';

    public const string CALLER = '16315553602';

    public const string APP_SECRET = 'wa-call-secret';

    public const string SDP_SENTINEL = 'SENTINEL-SDP-9f3a1c';

    public static function sdp(): string
    {
        return "v=0\r\no=- 46117 2 IN IP4 127.0.0.1\r\ns=".self::SDP_SENTINEL."\r\nt=0 0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\na=setup:actpass\r\n";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function connect(array $overrides = []): array
    {
        return array_merge([
            'id' => 'wacid.CALL-1',
            'to' => self::BUSINESS_NUMBER,
            'from' => self::CALLER,
            'event' => 'connect',
            'direction' => 'USER_INITIATED',
            'timestamp' => (string) Carbon::now()->getTimestamp(),
            'session' => ['sdp_type' => 'offer', 'sdp' => self::sdp()],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function terminate(array $overrides = []): array
    {
        return array_merge([
            'id' => 'wacid.CALL-1',
            'to' => self::BUSINESS_NUMBER,
            'from' => self::CALLER,
            'event' => 'terminate',
            'direction' => 'USER_INITIATED',
            'timestamp' => (string) Carbon::now()->getTimestamp(),
            'status' => 'FAILED',
        ], $overrides);
    }

    /**
     * One webhook `changes[]` item, the shape WhatsAppCallEvent::fromWebhook() takes.
     *
     * @param  array<int, mixed>  $calls
     * @param  array<string, mixed>  $valueExtra
     * @return array<string, mixed>
     */
    public static function change(array $calls, array $valueExtra = []): array
    {
        return [
            'field' => 'calls',
            'value' => array_merge([
                'messaging_product' => 'whatsapp',
                'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID, 'display_phone_number' => self::BUSINESS_NUMBER],
                'contacts' => [['profile' => ['name' => 'Caller Name'], 'wa_id' => self::CALLER]],
                'calls' => $calls,
            ], $valueExtra),
        ];
    }

    /**
     * @param  array<int, mixed>  $calls
     * @param  array<string, mixed>  $valueExtra
     * @return array<string, mixed>
     */
    public static function payload(array $calls, array $valueExtra = []): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'WABA-1', 'changes' => [self::change($calls, $valueExtra)]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function signed(array $payload, string $secret = self::APP_SECRET): array
    {
        return ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', (string) json_encode($payload), $secret)];
    }

    /**
     * A WhatsApp source with calling enabled and an app secret — the state in
     * which calls are actually processed.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $config
     */
    public static function source(Tenant $tenant, array $overrides = [], array $config = []): LeadSource
    {
        return LeadSource::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'type' => LeadSourceType::Whatsapp,
            'config' => array_merge([
                'phone_number_id' => self::PHONE_NUMBER_ID,
                'verify_token' => 'verify-token',
                'app_secret' => self::APP_SECRET,
            ], $config),
            'calling_enabled' => true,
        ], $overrides));
    }
}
