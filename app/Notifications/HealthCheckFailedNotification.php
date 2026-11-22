<?php

namespace App\Notifications;

use App\Services\MailSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Spatie\Health\Notifications\CheckFailedNotification;

/**
 * Spatie Health's failed-check email, sent through the "Monitoring alerts"
 * account (Admin → Settings → Email settings) when one is saved. When it
 * isn't, this is exactly the package's own email.
 */
class HealthCheckFailedNotification extends CheckFailedNotification
{
    public function toMail(): MailMessage
    {
        $message = parent::toMail();
        $alerts = app(MailSettings::class)->account(MailSettings::MONITORING_ALERTS);

        if ($alerts !== null) {
            $message->mailer(MailSettings::ALERTS_MAILER)->from($alerts['from_address'], $alerts['from_name']);
        }

        return $message;
    }
}
