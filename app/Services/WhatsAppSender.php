<?php

namespace App\Services;

use App\Contracts\MessageSenderInterface;
use App\Models\Conversation;
use App\Support\SendResult;
use Illuminate\Support\Facades\Http;

/**
 * Feature #15 — sends a free-form WhatsApp Cloud API text message.
 * Credentials are ALWAYS read fresh from $conversation->leadSource->config
 * here, never cached or passed in a job payload (see MessageSenderInterface's
 * own docblock) — never a global/shared Meta credential. A network-level
 * failure (Illuminate\Http\Client\ConnectionException) is deliberately left
 * uncaught here, so it propagates to the queued job's own retry handling —
 * only a confirmed platform-level rejection (a non-2xx HTTP response) is
 * captured as a SendResult::failure(), per the approved retry policy: a
 * transient infrastructure failure may be retried at the job level, but a
 * confirmed platform send failure must never trigger an automatic re-send.
 */
class WhatsAppSender implements MessageSenderInterface
{
    public function send(Conversation $conversation, string $body): SendResult
    {
        $config = $conversation->leadSource->config ?? [];
        $phoneNumberId = $config['phone_number_id'] ?? null;
        $accessToken = $config['access_token'] ?? null;

        if (! is_string($phoneNumberId) || $phoneNumberId === '' || ! is_string($accessToken) || $accessToken === '') {
            return SendResult::failure('WhatsApp credentials are not configured for this source.');
        }

        $response = Http::withToken($accessToken)->timeout(15)->post(
            "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages",
            [
                'messaging_product' => 'whatsapp',
                'to' => $conversation->external_thread_id,
                'type' => 'text',
                'text' => ['body' => $body],
            ],
        );

        if (! $response->successful()) {
            return SendResult::failure(
                $response->json('error.message') ?? 'WhatsApp rejected the message.',
                $response->json(),
            );
        }

        $externalMessageId = $response->json('messages.0.id');

        return SendResult::success(is_string($externalMessageId) ? $externalMessageId : null, $response->json());
    }
}
