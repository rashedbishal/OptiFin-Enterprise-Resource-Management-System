<?php

namespace App\Enums;

/**
 * Feature #15 — the three conversational channels a Conversation/Message can
 * belong to. Deliberately a separate, narrower enum from LeadSourceType
 * (which also includes Email/WebsiteForm/LinkedInLeadGen/Manual/CsvImport —
 * none of which have a two-way "conversation" concept), not a reuse of it:
 * allowing e.g. `csv_import` as a message channel would be a real data-model
 * correctness gap. A small, fixed, engineering-controlled set, matching this
 * module's own ConsentType/DealStatus/TaskStatus precedent.
 */
enum MessageChannel: string
{
    /** Ceiling for an outbound PDF on any channel: 25 MB. */
    public const int MAX_DOCUMENT_BYTES = 25 * 1024 * 1024;

    case Whatsapp = 'whatsapp';
    case Messenger = 'messenger';
    case Instagram = 'instagram';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Whatsapp => 'WhatsApp',
            self::Messenger => 'Messenger',
            self::Instagram => 'Instagram',
        };
    }

    /**
     * @return LeadSourceType the LeadSourceType this channel is captured
     *                        under — the only supported mapping direction,
     *                        since not every LeadSourceType has a channel.
     */
    public function leadSourceType(): LeadSourceType
    {
        return match ($this) {
            self::Whatsapp => LeadSourceType::Whatsapp,
            self::Messenger => LeadSourceType::FacebookMessenger,
            self::Instagram => LeadSourceType::Instagram,
        };
    }

    public static function fromLeadSourceType(LeadSourceType $type): ?self
    {
        return match ($type) {
            LeadSourceType::Whatsapp => self::Whatsapp,
            LeadSourceType::FacebookMessenger => self::Messenger,
            LeadSourceType::Instagram => self::Instagram,
            default => null,
        };
    }

    /**
     * The standard messaging-window duration Meta currently documents for
     * this channel — verified independently per channel (Final Architecture
     * Validation Report), not assumed shared. All three happen to be 24
     * hours today, but each is its own named constant so a future platform
     * policy change to one never silently affects the others.
     */
    public function windowHours(): int
    {
        return match ($this) {
            self::Whatsapp => 24,
            self::Messenger => 24,
            self::Instagram => 24,
        };
    }

    /**
     * Outbound media rules, per channel, from Meta's current documentation
     * (WhatsApp Cloud API media/document messages; Messenger Send API and
     * Attachment Upload API; Instagram Messaging API, both Facebook Login
     * and Instagram Login — confirmed with Meta 2026-09). Deliberately the
     * conservative reading where the docs and behaviour disagree
     * (Instagram gif is not offered), and PDFs are capped at
     * MAX_DOCUMENT_BYTES on every channel even where Meta allows more
     * (WhatsApp: 100 MB), so one upload never ties up a web request or a
     * queue worker for minutes. Each channel stays its own value so a
     * future Meta change to one never silently affects the others.
     *
     * @return array<int, string>
     */
    public function imageMimeTypes(): array
    {
        return match ($this) {
            self::Whatsapp => ['image/jpeg', 'image/png'],
            self::Messenger => ['image/jpeg', 'image/png', 'image/gif'],
            self::Instagram => ['image/jpeg', 'image/png'],
        };
    }

    public function imageMaxBytes(): int
    {
        return match ($this) {
            self::Whatsapp => 5 * 1024 * 1024,
            self::Messenger => 25 * 1024 * 1024,
            self::Instagram => 8 * 1024 * 1024,
        };
    }

    /**
     * PDF only — the one document type all three channels accept.
     *
     * @return array<int, string>
     */
    public function documentMimeTypes(): array
    {
        return ['application/pdf'];
    }

    public function documentMaxBytes(): int
    {
        return match ($this) {
            self::Whatsapp => self::MAX_DOCUMENT_BYTES,
            self::Messenger => self::MAX_DOCUMENT_BYTES,
            self::Instagram => self::MAX_DOCUMENT_BYTES,
        };
    }

    /**
     * Whether the platform lets a caption travel inside the same message as
     * the media. WhatsApp does (max 1024 chars); Messenger and Instagram
     * do not — a text and a file there are always two separate messages.
     */
    public function supportsMediaCaption(): bool
    {
        return $this === self::Whatsapp;
    }

    public function mediaCaptionMaxLength(): int
    {
        return 1024;
    }
}
