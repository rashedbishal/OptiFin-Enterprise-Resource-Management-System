<?php

namespace App\Services;

use App\Enums\CallingApiError;
use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Support\CallingApiResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * WhatsApp inbound calling — the one place that talks to Meta's Calling API
 * (`/{phone_number_id}/settings` and `/{phone_number_id}/calls`).
 *
 * The client is bound to ONE already-resolved LeadSource and reads that
 * source's own decrypted `phone_number_id` and `access_token` — there is no way
 * to hand it a different phone number, token, tenant or source id, so it can
 * never be pointed at another tenant's number. Resolving and authorizing the
 * source is the caller's job.
 *
 * Retry policy: only GET settings is retried, once, on a connection failure or
 * a 5xx — a read is safe to repeat. Every POST (enable/disable, and all four
 * call actions) is sent exactly once: a timeout on a POST is ambiguous (Meta may
 * have applied it), and repeating a state change or a call action blindly is
 * worse than reporting it.
 *
 * Never sent or logged: the token in a query string, `include_sip_credentials`,
 * an SDP, or a customer phone number. The call-action methods are capability
 * only in Step 3 — nothing in the app invokes them yet.
 */
class WhatsAppCallingClient
{
    public function __construct(private readonly LeadSource $source)
    {
        if ($source->type !== LeadSourceType::Whatsapp) {
            throw new InvalidArgumentException('WhatsAppCallingClient requires a WhatsApp lead source.');
        }
    }

    /**
     * GET the number's calling settings. `data`: calling_status (string|null),
     * sip_status (string|null), restrictions (list of {type, expiration}).
     */
    public function getSettings(): CallingApiResult
    {
        return $this->request('GET', 'settings', null, retryOnce: true, parse: fn (array $body): array => $this->parseSettings($body));
    }

    /**
     * Turn calling on or off at Meta. Exactly `{"calling":{"status":...}}`.
     */
    public function setCallingStatus(bool $enabled): CallingApiResult
    {
        return $this->request('POST', 'settings', [
            'calling' => ['status' => $enabled ? 'ENABLED' : 'DISABLED'],
        ], retryOnce: false, parse: fn (array $body): ?array => $this->requireSuccessFlag($body));
    }

    public function preAccept(string $callId, string $sdpAnswer): CallingApiResult
    {
        return $this->callAction('pre_accept', $callId, $sdpAnswer);
    }

    public function accept(string $callId, string $sdpAnswer): CallingApiResult
    {
        return $this->callAction('accept', $callId, $sdpAnswer);
    }

    public function reject(string $callId): CallingApiResult
    {
        return $this->callAction('reject', $callId);
    }

    public function terminate(string $callId): CallingApiResult
    {
        return $this->callAction('terminate', $callId);
    }

    private function callAction(string $action, string $callId, ?string $sdpAnswer = null): CallingApiResult
    {
        $body = [
            'messaging_product' => 'whatsapp',
            'call_id' => $callId,
            'action' => $action,
        ];

        if ($sdpAnswer !== null) {
            $body['session'] = ['sdp_type' => 'answer', 'sdp' => $sdpAnswer];
        }

        return $this->request('POST', 'calls', $body, retryOnce: false, parse: fn (array $response): ?array => $this->requireSuccessFlag($response));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  callable(array<string, mixed>): (array<string, mixed>|null)  $parse  Returns the safe data array, or null when the 2xx body is not what the endpoint documents.
     */
    private function request(string $method, string $path, ?array $body, bool $retryOnce, callable $parse): CallingApiResult
    {
        $config = $this->source->config;
        $phoneNumberId = is_array($config) ? ($config['phone_number_id'] ?? null) : null;
        $accessToken = is_array($config) ? ($config['access_token'] ?? null) : null;

        if (! is_string($phoneNumberId) || $phoneNumberId === '' || ! is_string($accessToken) || $accessToken === '') {
            return CallingApiResult::failure(CallingApiError::Validation, null, null, null, 0);
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/%s',
            config('calling.graph_version'),
            rawurlencode($phoneNumberId),
            $path,
        );

        $startedAt = hrtime(true);
        $attempts = $retryOnce ? 2 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $isLastAttempt = $attempt === $attempts;

            try {
                $pending = $this->pending($accessToken);
                $response = $method === 'GET' ? $pending->get($url) : $pending->asJson()->post($url, $body ?? []);
            } catch (ConnectionException) {
                if ($isLastAttempt) {
                    return CallingApiResult::failure(CallingApiError::Network, null, null, null, $this->elapsedMs($startedAt));
                }

                continue;
            }

            if ($response->serverError() && ! $isLastAttempt) {
                continue;
            }

            return $this->toResult($response, $parse, $this->elapsedMs($startedAt));
        }

        return CallingApiResult::failure(CallingApiError::Unknown, null, null, null, $this->elapsedMs($startedAt));
    }

    private function pending(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->timeout((int) config('calling.api_timeout_seconds'))
            ->connectTimeout((int) config('calling.api_connect_timeout_seconds'));
    }

    /**
     * @param  callable(array<string, mixed>): (array<string, mixed>|null)  $parse
     */
    private function toResult(Response $response, callable $parse, int $durationMs): CallingApiResult
    {
        $status = $response->status();
        $json = $response->json();

        if ($response->successful()) {
            $data = is_array($json) ? $parse($json) : null;

            if ($data === null) {
                return CallingApiResult::failure(CallingApiError::InvalidResponse, null, $status, null, $durationMs);
            }

            return CallingApiResult::success($status, $data, $durationMs);
        }

        $errorBody = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = isset($errorBody['code']) && is_numeric($errorBody['code']) ? (int) $errorBody['code'] : null;
        $trace = isset($errorBody['fbtrace_id']) && is_string($errorBody['fbtrace_id']) ? $errorBody['fbtrace_id'] : null;

        return CallingApiResult::failure(CallingApiError::classify($code, $status), $code, $status, $trace, $durationMs);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function requireSuccessFlag(array $body): ?array
    {
        return ($body['success'] ?? null) === true ? [] : null;
    }

    /**
     * Parse a settings GET defensively. Only `calling.status`, `calling.sip.status`
     * and the restriction type/expiration are kept — never Meta's free text. Where
     * Meta places the restriction list (and how it spells the key) is not pinned
     * down by its docs, so the plausible spellings are all tried.
     *
     * @param  array<string, mixed>  $body
     * @return array{calling_status: ?string, sip_status: ?string, restrictions: array<int, array{type: string, expiration: ?int}>}
     */
    private function parseSettings(array $body): array
    {
        $calling = is_array($body['calling'] ?? null) ? $body['calling'] : [];

        $status = $calling['status'] ?? null;
        $sip = is_array($calling['sip'] ?? null) ? ($calling['sip']['status'] ?? null) : null;

        return [
            'calling_status' => is_string($status) && $status !== '' ? strtoupper($status) : null,
            'sip_status' => is_string($sip) && $sip !== '' ? strtoupper($sip) : null,
            'restrictions' => $this->parseRestrictions($calling, $body),
        ];
    }

    /**
     * @param  array<string, mixed>  $calling
     * @param  array<string, mixed>  $body
     * @return array<int, array{type: string, expiration: ?int}>
     */
    private function parseRestrictions(array $calling, array $body): array
    {
        $keys = ['restrictions', 'restriction_list', 'restrictions_list'];
        $candidate = null;

        foreach ([$calling, $body] as $container) {
            foreach ($keys as $key) {
                if (isset($container[$key]) && is_array($container[$key])) {
                    $candidate = $container[$key];
                    break 2;
                }
            }
        }

        if ($candidate === null) {
            return [];
        }

        if (! array_is_list($candidate)) {
            foreach ($keys as $key) {
                if (isset($candidate[$key]) && is_array($candidate[$key])) {
                    $candidate = $candidate[$key];
                    break;
                }
            }
        }

        $restrictions = [];

        foreach ($candidate as $item) {
            if (! is_array($item) || ! isset($item['type']) || ! is_string($item['type']) || $item['type'] === '') {
                continue;
            }

            $expiration = $item['expiration'] ?? null;

            $restrictions[] = [
                'type' => $item['type'],
                'expiration' => is_numeric($expiration) ? (int) $expiration : null,
            ];
        }

        return $restrictions;
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
