<?php

namespace App\Notifications;

use App\Services\MailSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * "A new kind of error happened" email (App\Services\ErrorAlertNotifier).
 * Sent through the "Monitoring alerts" account when one is saved, like the
 * other ops alerts. Not queued: it is sent from the error report itself.
 */
class ErrorAlertNotification extends Notification
{
    /**
     * @param  array{type: string, message: string, location: string, where: string, time: string, occurrences: int, trace: list<string>, first: bool}  $error
     */
    public function __construct(public readonly array $error) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $error = $this->error;

        $message = (new MailMessage)
            ->error()
            ->subject('['.config('app.name').' Error] '.class_basename($error['type']).' in '.$error['location'])
            ->line($error['first']
                ? __('A new kind of error happened in :app.', ['app' => config('app.name')])
                : __('This error is still happening (:count times so far). You get at most one email per day about it.', ['count' => $error['occurrences']]))
            ->line('**'.__('Error').':** '.$error['type'])
            ->line('**'.__('Message').':** '.$error['message'])
            ->line('**'.__('Where in the code').':** '.$error['location'])
            ->line('**'.__('Page / command').':** '.$error['where'])
            ->line('**'.__('Time').':** '.$error['time'])
            ->line('**'.__('Times so far').':** '.$error['occurrences'])
            ->line(__('First lines of the stack trace (for your developer):'))
            ->line(new HtmlString('<pre style="font-size:12px;white-space:pre-wrap">'.e(implode("\n", $error['trace'])).'</pre>'));

        $settings = app(MailSettings::class);

        if ($settings->isConfigured(MailSettings::MONITORING_ALERTS)) {
            $message->mailer(MailSettings::ALERTS_MAILER);
        }

        return $message;
    }
}
