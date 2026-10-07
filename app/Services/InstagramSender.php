<?php

namespace App\Services;

use App\Contracts\MessageSenderInterface;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\SendResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Same two-call shape as MessengerSender::sendMedia() — upload to
     * message_attachments (with `platform=instagram`), then send the
     * returned attachment_id — against whichever host this source's own
     * auth_mode selects. Instagram has no caption field either, so any
     * accompanying text is its own, separate Message.
     *
     * NOTE: the multipart upload step is per Meta's own guidance for this
     * API but has not been exercised against a live Instagram account
     * here; a rejection surfaces as a normal, resendable Failed message
     * carrying Meta's own error text.
     */
    public function sendMedia(Conversation $conversation, Message $message): SendResult
    {
        $config = $conversation->leadSource->config ?? [];
        $accessToken = $config['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return SendResult::failure('Instagram credentials are not configured for this source.');
        }

        $disk = Storage::disk('local');

        if ($message->attachment_path === null || ! $disk->exists($message->attachment_path)) {
            return SendResult::failure('The attached file is no longer available.');
        }

        $host = ($config['auth_mode'] ?? 'facebook') === 'instagram'
            ? 'graph.instagram.com'
            : 'graph.facebook.com';
        $type = $message->message_type === MessageType::Image ? 'image' : 'file';

        $upload = Http::withToken($accessToken)->timeout(60)
            ->attach('filedata', $disk->readStream($message->attachment_path), (string) $message->attachment_name, ['Content-Type' => (string) $message->attachment_mime])
            ->post("https://{$host}/v20.0/me/message_attachments", [
                'platform' => 'instagram',
                'message' => json_encode(['attachment' => ['type' => $type, 'payload' => ['is_reusable' => true]]]),
            ]);

        $attachmentId = $upload->json('attachment_id');

        if (! $upload->successful() || ! is_string($attachmentId) || $attachmentId === '') {
            return SendResult::failure(
                $upload->json('error.message') ?? 'Instagram rejected the file upload.',
                $upload->json(),
            );
        }

        $response = Http::withToken($accessToken)->timeout(15)->post(
            "https://{$host}/v20.0/me/messages",
            [
                'recipient' => ['id' => $conversation->external_thread_id],
                'message' => ['attachment' => ['type' => $type, 'payload' => ['attachment_id' => $attachmentId]]],
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

    /**
     * Template messages are WhatsApp-only in this app — see
     * MessengerSender::sendTemplate()'s identical docblock.
     */
    public function sendTemplate(Conversation $conversation, Message $message): SendResult
    {
        return SendResult::failure('Template messages are only supported for WhatsApp.');
    }
}
