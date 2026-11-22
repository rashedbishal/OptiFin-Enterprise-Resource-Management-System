<?php

namespace App\Support;

use App\Services\MailSettings;
use Spatie\Health\Notifications\Notifiable;

/**
 * Who receives Spatie Health's failed-check emails: the "Monitoring alerts"
 * recipient list from Admin → Settings → Email settings when that account is
 * saved, otherwise the package's configured address (OPS_ALERT_EMAIL), as
 * before. An empty address means no email is sent.
 */
class HealthAlertNotifiable extends Notifiable
{
    /**
     * @return string|list<string>
     */
    public function routeNotificationForMail(): string|array
    {
        $settings = app(MailSettings::class);

        if ($settings->isConfigured(MailSettings::MONITORING_ALERTS)) {
            return $settings->alertRecipients();
        }

        return (string) config('health.notifications.mail.to');
    }
}
