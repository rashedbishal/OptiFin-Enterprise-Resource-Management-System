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
}
