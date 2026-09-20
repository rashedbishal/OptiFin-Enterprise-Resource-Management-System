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

    /**
     * Two calls: upload the stored file to /me/message_attachments
     * (multipart `filedata`, returns an attachment_id), then send a message
     * whose only content is that attachment — Messenger has no caption
     * field, so any accompanying text is its own, separate Message. The
     * attachment id is used once, for this one message, and never shared
     * or reused across messages or tenants.
     */
    public function sendMedia(Conversation $conversation, Message $message): SendResult
    {
        $config = $conversation->leadSource->config ?? [];
        $pageAccessToken = $config['page_access_token'] ?? null;

        if (! is_string($pageAccessToken) || $pageAccessToken === '') {
            return SendResult::failure('Messenger credentials are not configured for this source.');
        }

        $disk = Storage::disk('local');

        if ($message->attachment_path === null || ! $disk->exists($message->attachment_path)) {
            return SendResult::failure('The attached file is no longer available.');
        }

        $type = $message->message_type === MessageType::Image ? 'image' : 'file';

        $upload = Http::withToken($pageAccessToken)->timeout(60)
            ->attach('filedata', $disk->readStream($message->attachment_path), (string) $message->attachment_name, ['Content-Type' => (string) $message->attachment_mime])
            ->post('https://graph.facebook.com/v20.0/me/message_attachments', [
                'message' => json_encode(['attachment' => ['type' => $type, 'payload' => ['is_reusable' => true]]]),
            ]);

        $attachmentId = $upload->json('attachment_id');

        if (! $upload->successful() || ! is_string($attachmentId) || $attachmentId === '') {
            return SendResult::failure(
                $upload->json('error.message') ?? 'Messenger rejected the file upload.',
                $upload->json(),
            );
        }

        $response = Http::withToken($pageAccessToken)->timeout(15)->post(
            'https://graph.facebook.com/v20.0/me/messages',
            [
                'recipient' => ['id' => $conversation->external_thread_id],
                'message' => ['attachment' => ['type' => $type, 'payload' => ['attachment_id' => $attachmentId]]],
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
