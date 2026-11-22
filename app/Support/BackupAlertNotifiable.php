<?php

namespace App\Support;

use App\Services\MailSettings;
use Spatie\Backup\Notifications\Notifiable;

/**
 * Who receives backup failure emails: the "Monitoring alerts" recipient list
 * (Admin → Settings → Email settings) when saved, otherwise OPS_ALERT_EMAIL.
 * An empty address means no email is sent.
 */
class BackupAlertNotifiable extends Notifiable
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

        $fallback = config('ops.alert_email');

        return is_string($fallback) && filter_var($fallback, FILTER_VALIDATE_EMAIL) !== false ? $fallback : '';
    }
}
