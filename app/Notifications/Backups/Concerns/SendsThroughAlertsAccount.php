<?php

namespace App\Notifications\Backups\Concerns;

use App\Services\MailSettings;
use Illuminate\Notifications\Messages\MailMessage;

trait SendsThroughAlertsAccount
{
    /**
     * Switches the message to the "Monitoring alerts" mailer and sender when
     * that account is saved; otherwise leaves the package's message as is.
     */
    protected function throughAlertsAccount(MailMessage $message): MailMessage
    {
        $alerts = app(MailSettings::class)->account(MailSettings::MONITORING_ALERTS);

        if ($alerts !== null) {
            $message->mailer(MailSettings::ALERTS_MAILER)->from($alerts['from_address'], $alerts['from_name']);
        }

        return $message;
    }
}
