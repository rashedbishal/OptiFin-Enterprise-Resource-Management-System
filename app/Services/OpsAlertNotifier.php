<?php

namespace App\Services;

use App\Notifications\OpsAlertNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * OPS-1 — the single place an operator alert email leaves the application.
 * Delivery is best-effort by design: it can never throw, so a missing or
 * broken alert setup cannot make a scheduled command, a queued job or the
 * request that triggered it fail. Callers own their own logging and
 * throttling; this only sends.
 */
class OpsAlertNotifier
{
    public function isConfigured(): bool
    {
        return $this->recipient() !== null;
    }

    /**
     * @param  array<string, scalar|null>  $context  Safe operational facts only.
     * @return bool True only when an email was actually handed to the mailer.
     */
    public function send(string $subject, array $context): bool
    {
        $recipient = $this->recipient();

        if ($recipient === null) {
            return false;
        }

        try {
            NotificationFacade::route('mail', $recipient)->notifyNow(new OpsAlertNotification($subject, $context));

            return true;
        } catch (Throwable $e) {
            // Class only: a mailer exception message can echo the recipient
            // address or SMTP connection details.
            Log::warning('An ops alert email could not be delivered.', ['exception' => $e::class]);

            return false;
        }
    }

    /**
     * Sends any alert notification to the same recipients as send(), with
     * the same guarantee: it never throws.
     *
     * @return bool True only when an email was actually handed to the mailer.
     */
    public function sendNotification(Notification $notification): bool
    {
        $recipient = $this->recipient();

        if ($recipient === null) {
            return false;
        }

        try {
            NotificationFacade::route('mail', $recipient)->notifyNow($notification);

            return true;
        } catch (Throwable $e) {
            Log::warning('An ops alert email could not be delivered.', ['exception' => $e::class]);

            return false;
        }
    }

    /**
     * The "Monitoring alerts" account saved at Admin → Settings → Email
     * settings sends to its own recipient list; when it isn't saved, the
     * single OPS_ALERT_EMAIL address from .env is used exactly as before.
     *
     * @return string|list<string>|null
     */
    private function recipient(): string|array|null
    {
        $settings = app(MailSettings::class);

        if ($settings->isConfigured(MailSettings::MONITORING_ALERTS)) {
            $recipients = $settings->alertRecipients();

            return $recipients === [] ? null : $recipients;
        }

        $email = config('ops.alert_email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
