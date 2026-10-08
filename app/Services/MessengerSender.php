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
 * conversation"). See WhatsAppSender's docblock for the
 * credential-resolution and network-failure-vs-platform-rejection
 * handling, identical here. sendTemplate() below is the one send path that
 * works once that window has closed, via Meta's Utility Message Templates
 * (the replacement for the now-deprecated Message Tags feature) — see its
 * own docblock.
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

    /**
     * Sends an already-approved Messenger Utility Message Template — Meta's
     * February 2026 replacement for the deprecated Message Tags feature,
     * the one send path that works even once the 24-hour messaging window
     * has closed. Confirmed directly with Meta before building this:
     * `pages_messaging` alone is NOT sufficient here, unlike WhatsApp —
     * the Page's token also needs the separate `pages_utility_messaging`
     * scope (see MetaPermissionsController::REQUIRED_SCOPES), or Meta
     * rejects the send with a permission error surfaced as a normal
     * SendResult::failure() below, the same as any other platform
     * rejection.
     *
     * One further difference from WhatsApp's template send, confirmed with
     * Meta: the endpoint takes the Page id explicitly (`/{page_id}/messages`,
     * not `/me/messages` like send()/sendMedia() above) — otherwise the
     * payload shape mirrors WhatsApp's own template send almost exactly
     * (`language` as `{code: ...}`, `parameters` nested under
     * `components[].type=body`), which is also what the second, more
     * detailed round of guidance on this (an actual example payload against
     * a real Page id) showed, superseding an earlier, flatter shape a first
     * round had suggested — NOT YET CONFIRMED against a real send, same as
     * every other Meta-facing capability in this app: don't treat this as
     * done until it's been fired at a real Page. Positional variables only
     * for Phase 1 (no named `parameter_name` parameters), matching
     * WhatsAppMessageTemplate's own scope — $message's template_name/
     * template_language/template_variables columns are the same frozen
     * send-time snapshot WhatsAppSender::sendTemplate() uses, never a live
     * join back to the MessengerMessageTemplate catalog row.
     *
     * Uses Graph API v26.0 (per Meta's own current documentation for this
     * specific, newly-introduced capability) rather than this file's
     * existing v20.0 — send()/sendMedia() above are deliberately left on
     * their own pinned version, unrelated and already working.
     */
    public function sendTemplate(Conversation $conversation, Message $message): SendResult
    {
        $config = $conversation->leadSource->config ?? [];
        $pageId = $config['page_id'] ?? null;
        $pageAccessToken = $config['page_access_token'] ?? null;

        if (! is_string($pageId) || $pageId === '' || ! is_string($pageAccessToken) || $pageAccessToken === '') {
            return SendResult::failure('Messenger credentials are not configured for this source.');
        }

        $variables = is_array($message->template_variables) ? $message->template_variables : [];

        $response = Http::withToken($pageAccessToken)->timeout(15)->post(
            "https://graph.facebook.com/v26.0/{$pageId}/messages",
            [
                'recipient' => ['id' => $conversation->external_thread_id],
                'messaging_type' => 'UTILITY',
                'message' => [
                    'template' => [
                        'name' => $message->template_name,
                        'language' => ['code' => $message->template_language],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $variables),
                        ]],
                    ],
                ],
            ],
        );

        if (! $response->successful()) {
            return SendResult::failure(
                $response->json('error.message') ?? 'Messenger rejected the template message.',
                $response->json(),
            );
        }

        $externalMessageId = $response->json('message_id');

        return SendResult::success(is_string($externalMessageId) ? $externalMessageId : null, $response->json());
    }
}
