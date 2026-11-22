<?php

namespace App\Mail;

use App\Services\MailSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The "Send test email" message from Admin → Settings → Email settings —
 * proves one saved sending account can actually deliver mail.
 */
class EmailSettingsTest extends Mailable
{
    public function __construct(public string $purpose) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':app — test email (:account)', ['app' => config('app.name'), 'account' => $this->accountLabel()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-settings-test',
            with: ['accountLabel' => $this->accountLabel()],
        );
    }

    private function accountLabel(): string
    {
        return $this->purpose === MailSettings::USER_EMAILS ? __('User emails') : __('Monitoring alerts');
    }
}
