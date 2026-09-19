<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * OPS-1 — a plain operator alert (scheduler failure, IMAP connection outage),
 * delivered to the on-demand OPS_ALERT_EMAIL address only. Deliberately not
 * queued (sent with notifyNow() by App\Services\OpsAlertNotifier): the alert
 * exists to report background infrastructure trouble, so it must not depend
 * on the queue worker being alive. The context is limited to safe operational
 * facts chosen by the caller — never tenant lead data or credentials.
 */
class OpsAlertNotification extends Notification
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(private readonly string $subject, private readonly array $context) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('['.config('app.name').' Ops] '.$this->subject)
            ->line($this->subject);

        foreach ($this->context as $key => $value) {
            $message->line($key.': '.(is_scalar($value) ? (string) $value : ''));
        }

        return $message;
    }
}
