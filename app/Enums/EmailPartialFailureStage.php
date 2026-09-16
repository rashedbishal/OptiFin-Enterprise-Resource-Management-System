<?php

namespace App\Enums;

/**
 * EMAIL-LOSS-1 (event-replay fix) — which post-persist step failed after a
 * Lead was already durably created or appended, inside
 * App\Jobs\ProcessEmailLeadSource::runPostPersistSteps(). Distinguishing
 * these two is the entire point of this enum: a retry must re-fire the
 * event when it never dispatched (Event), and must never re-fire an event
 * that already returned successfully (Attachments) — see
 * App\Exceptions\EmailMessagePartiallyProcessed's own docblock.
 */
enum EmailPartialFailureStage: string
{
    case Event = 'event';
    case Attachments = 'attachments';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
