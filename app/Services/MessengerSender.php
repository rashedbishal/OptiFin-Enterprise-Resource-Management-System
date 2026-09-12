<?php

namespace App\Services;

use App\Contracts\MessageSenderInterface;
use App\Models\Conversation;
use App\Support\SendResult;
use Illuminate\Support\Facades\Http;

/**
 * Feature #15 — sends a free-form Messenger Send API text message, within
 * the standard 24-hour messaging window only (`messaging_type: RESPONSE` —
 * the Send API's own designation for "replying to a user-initiated
 * conversation," matching Phase 1's exact approved scope; message-tag-based
 * sending outside the window is explicitly out of scope and never used
 * here). See WhatsAppSender's docblock for the credential-resolution and
 * network-failure-vs-platform-rejection handling, identical here.
 */
class MessengerSender implements MessageSenderInterface
{
    public function send(Conversation $conversation, string $body): SendResult
    {
        $config = $conversation->leadSource->config ?? [];
        $pageAccessToken = $config['page_access_token'] ?? null;

        if (! is_string($pageAccessToken) || $pageAccessToken === '') {
            return SendResult::failure('Messenger credentials are not configured for this source.');
        }

        $response = Http::withToken($pageAccessToken)->timeout(15)->post(
            'https://graph.facebook.com/v20.0/me/messages',
            [
                'recipient' => ['id' => $conversation->external_thread_id],
                'message' => ['text' => $body],
                'messaging_type' => 'RESPONSE',
            ],
        );

        if (! $response->successful()) {
            return SendResult::failure(
                $response->json('error.message') ?? 'Messenger rejected the message.',
                $response->json(),
            );
        }

        $externalMessageId = $response->json('message_id');

        return SendResult::success(is_string($externalMessageId) ? $externalMessageId : null, $response->json());
    }
}
