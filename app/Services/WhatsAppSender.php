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

    /**
     * Two calls: upload the stored file to /{phone_number_id}/media
     * (multipart, returns a media id), then send an `image`/`document`
     * message referencing that id — the caption, if any, rides in the same
     * message (WhatsApp supports it, max 1024 chars). A network-level
     * failure on either call is left uncaught, exactly like send().
     */
    public function sendMedia(Conversation $conversation, Message $message): SendResult
    {
        $config = $conversation->leadSource->config ?? [];
        $phoneNumberId = $config['phone_number_id'] ?? null;
        $accessToken = $config['access_token'] ?? null;

        if (! is_string($phoneNumberId) || $phoneNumberId === '' || ! is_string($accessToken) || $accessToken === '') {
            return SendResult::failure('WhatsApp credentials are not configured for this source.');
        }

        $disk = Storage::disk('local');

        if ($message->attachment_path === null || ! $disk->exists($message->attachment_path)) {
            return SendResult::failure('The attached file is no longer available.');
        }

        $upload = Http::withToken($accessToken)->timeout(60)
            ->attach('file', $disk->readStream($message->attachment_path), (string) $message->attachment_name, ['Content-Type' => (string) $message->attachment_mime])
            ->post("https://graph.facebook.com/v20.0/{$phoneNumberId}/media", [
                'messaging_product' => 'whatsapp',
                'type' => $message->attachment_mime,
            ]);

        $mediaId = $upload->json('id');

        if (! $upload->successful() || ! is_string($mediaId) || $mediaId === '') {
            return SendResult::failure(
                $upload->json('error.message') ?? 'WhatsApp rejected the file upload.',
                $upload->json(),
            );
        }

        $type = $message->message_type === MessageType::Image ? 'image' : 'document';
        $media = ['id' => $mediaId];

        if ($message->body !== null && $message->body !== '') {
            $media['caption'] = $message->body;
        }

        if ($type === 'document') {
            $media['filename'] = $message->attachment_name;
        }

        $response = Http::withToken($accessToken)->timeout(15)->post(
            "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages",
            [
                'messaging_product' => 'whatsapp',
                'to' => $conversation->external_thread_id,
                'type' => $type,
                $type => $media,
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
