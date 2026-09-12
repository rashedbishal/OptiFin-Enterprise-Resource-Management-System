<?php

namespace App\Enums;

/**
 * Feature #15 — an outbound Message's delivery lifecycle. Meaningless for an
 * inbound Message (which is never "sent" by us) — inbound rows leave this
 * column null. `Failed` is a terminal state reached only by a synchronous
 * platform API rejection at send time (see OutboundEligibilityService/the
 * *Sender classes), never by an async status webhook — a failed send never
 * receives an `external_message_id`, so no later webhook can ever resolve
 * to a failed row in the first place; `rank()` therefore only needs to order
 * the four states an async status webhook can actually progress through.
 */
enum MessageStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * Ordinal position in the pending → sent → delivered → read progression
     * — used by ReconcileOutboundMessageStatus to enforce "never downgrade
     * an already-advanced status" when an out-of-order or duplicate webhook
     * status event arrives.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed => 99,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Read => 'Read',
            self::Failed => 'Failed',
        };
    }
}
