<?php

namespace App\Services;

use App\Contracts\MessageSenderInterface;
use App\Models\Conversation;
use App\Support\SendResult;
use Illuminate\Support\Facades\Http;

/**
 * Feature #15 — sends a free-form Instagram Messaging API text message.
 * Reuses the EXACT same auth_mode-based host branching
 * LeadWebhookController::fetchInstagramProfile() already uses for inbound
 * profile lookups — this codebase already supports both Instagram
 * architectures (Instagram Login via `graph.instagram.com`, or a
 * Page-linked connection via `graph.facebook.com`) per-tenant, chosen by
 * the existing `auth_mode` config value, never assumed. See
 * WhatsAppSender's docblock for credential-resolution and
 * network-failure-vs-platform-rejection handling, identical here.
 */
class InstagramSender implements MessageSenderInterface
{
    public function send(Conversation $conversation, string $body): SendResult
    {
        $config = $conversation->leadSource->config ?? [];
        $accessToken = $config['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return SendResult::failure('Instagram credentials are not configured for this source.');
        }

        $host = ($config['auth_mode'] ?? 'facebook') === 'instagram'
            ? 'graph.instagram.com'
            : 'graph.facebook.com';

        $response = Http::withToken($accessToken)->timeout(15)->post(
            "https://{$host}/v20.0/me/messages",
            [
                'recipient' => ['id' => $conversation->external_thread_id],
                'message' => ['text' => $body],
            ],
        );

        if (! $response->successful()) {
            return SendResult::failure(
                $response->json('error.message') ?? 'Instagram rejected the message.',
                $response->json(),
            );
        }

        $externalMessageId = $response->json('message_id');

        return SendResult::success(is_string($externalMessageId) ? $externalMessageId : null, $response->json());
    }
}
