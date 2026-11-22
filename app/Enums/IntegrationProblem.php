<?php

namespace App\Enums;

/**
 * One problem badge on Admin → Monitoring → Integrations — see
 * App\Services\IntegrationHealth for when each applies.
 */
enum IntegrationProblem: string
{
    case Quiet = 'quiet';
    case WebhookRejected = 'webhook_rejected';
    case InboxCannotConnect = 'inbox_cannot_connect';
    case EmailsFailed = 'emails_failed';
    case TokenInvalid = 'token_invalid';
    case TokenMissing = 'token_missing';
    case TokenExpiresSoon = 'token_expires_soon';
    case CallingOffAtMeta = 'calling_off_at_meta';

    public function label(): string
    {
        return match ($this) {
            self::Quiet => __('Quiet'),
            self::WebhookRejected => __('Webhook rejected'),
            self::InboxCannotConnect => __('Inbox can\'t connect'),
            self::EmailsFailed => __('Emails failed'),
            self::TokenInvalid => __('Token invalid'),
            self::TokenMissing => __('No token'),
            self::TokenExpiresSoon => __('Token expires soon'),
            self::CallingOffAtMeta => __('Calling off at Meta'),
        };
    }

    public function isSerious(): bool
    {
        return in_array($this, [self::WebhookRejected, self::InboxCannotConnect, self::TokenInvalid, self::TokenMissing], true);
    }

    public function color(): string
    {
        return $this->isSerious() ? 'red' : 'yellow';
    }
}
