<?php

namespace App\Enums;

/**
 * EMAIL-LOSS-1 — the durable outcome of one email message that failed
 * App\Jobs\ProcessEmailLeadSource::createLeadFromEmail() at least once.
 * Deliberately three states, not four: there is no "Retrying"/"Pending"
 * state distinct from Failed — attempts/next_retry_at on the Failed record
 * itself already carry that information, and no record is ever written
 * before an outcome is known (unlike LeadAttachmentStatus::Downloading,
 * there is no separate async step here to represent mid-flight).
 */
enum FailedEmailMessageStatus: string
{
    /**
     * Still eligible for a future retry attempt (attempts have not reached
     * the retry ceiling) via the leads:retry-failed-emails command.
     */
    case Failed = 'failed';

    /**
     * A retry attempt succeeded — resolved_lead_id/recovered_at are set.
     * Terminal: never retried again.
     */
    case Recovered = 'recovered';

    /**
     * Either the retry ceiling was reached, or a retry was determined to be
     * unsafe (the mailbox's UIDVALIDITY changed, the message could no
     * longer be located by its stored UID, or its Message-ID no longer
     * matches what was originally recorded at that UID). Terminal: never
     * retried again, but the record itself is never deleted — see
     * FailedEmailMessage's own docblock.
     */
    case Exhausted = 'exhausted';

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
            self::Failed => 'Failed',
            self::Recovered => 'Recovered',
            self::Exhausted => 'Exhausted',
        };
    }
}
