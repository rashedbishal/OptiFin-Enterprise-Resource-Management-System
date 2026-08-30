<?php

namespace App\Enums;

enum LeadSourceType: string
{
    case FacebookMessenger = 'facebook_messenger';
    case FacebookLeadAds = 'facebook_lead_ads';
    case Whatsapp = 'whatsapp';
    case Instagram = 'instagram';
    case Email = 'email';
    case WebsiteForm = 'website_form';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get the human-readable label for this lead source type.
     */
    public function label(): string
    {
        return match ($this) {
            self::FacebookMessenger => 'Facebook Messenger',
            self::FacebookLeadAds => 'Facebook Lead Ads',
            self::Whatsapp => 'WhatsApp',
            self::Instagram => 'Instagram',
            self::Email => 'Email',
            self::WebsiteForm => 'Website Form',
        };
    }

    /**
     * Get the Flux/Heroicon icon name representing this lead source type.
     */
    public function icon(): string
    {
        return match ($this) {
            self::FacebookMessenger => 'chat-bubble-left-right',
            self::FacebookLeadAds => 'document-text',
            self::Whatsapp => 'chat-bubble-oval-left-ellipsis',
            self::Instagram => 'camera',
            self::Email => 'envelope',
            self::WebsiteForm => 'globe-alt',
        };
    }

    /**
     * Get the configurable fields for this lead source type's `config` payload.
     *
     * @return array<int, array{key: string, label: string, type: string, sensitive: bool, options?: array<string, string>, depends_on?: string}>
     */
    public function configFields(): array
    {
        return match ($this) {
            self::FacebookMessenger => [
                ['key' => 'page_id', 'label' => 'Page ID', 'type' => 'text', 'sensitive' => false],
                ['key' => 'page_access_token', 'label' => 'Page Access Token', 'type' => 'password', 'sensitive' => true],
                ['key' => 'verify_token', 'label' => 'Verify Token', 'type' => 'password', 'sensitive' => true],
                ['key' => 'app_secret', 'label' => 'App Secret (enables webhook signature verification)', 'type' => 'password', 'sensitive' => true],
            ],
            self::FacebookLeadAds => [
                ['key' => 'page_id', 'label' => 'Page ID', 'type' => 'text', 'sensitive' => false],
                ['key' => 'page_access_token', 'label' => 'Page Access Token', 'type' => 'password', 'sensitive' => true],
                ['key' => 'verify_token', 'label' => 'Verify Token', 'type' => 'password', 'sensitive' => true],
                ['key' => 'app_secret', 'label' => 'App Secret (enables webhook signature verification)', 'type' => 'password', 'sensitive' => true],
            ],
            self::Whatsapp => [
                ['key' => 'phone_number_id', 'label' => 'Phone Number ID', 'type' => 'text', 'sensitive' => false],
                ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'sensitive' => true],
                ['key' => 'verify_token', 'label' => 'Verify Token', 'type' => 'password', 'sensitive' => true],
                ['key' => 'app_secret', 'label' => 'App Secret (enables webhook signature verification)', 'type' => 'password', 'sensitive' => true],
            ],
            self::Instagram => [
                ['key' => 'auth_mode', 'label' => 'Login Via', 'type' => 'select', 'sensitive' => false, 'options' => [
                    'facebook' => 'Instagram Login via Facebook',
                    'instagram' => 'Instagram Login via Instagram',
                ]],
                ['key' => 'instagram_account_id', 'label' => 'Instagram Business Account ID', 'type' => 'text', 'sensitive' => false, 'depends_on' => 'auth_mode'],
                ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'sensitive' => true, 'depends_on' => 'auth_mode'],
                ['key' => 'verify_token', 'label' => 'Verify Token', 'type' => 'password', 'sensitive' => true, 'depends_on' => 'auth_mode'],
                ['key' => 'app_secret', 'label' => 'App Secret (enables webhook signature verification)', 'type' => 'password', 'sensitive' => true, 'depends_on' => 'auth_mode'],
            ],
            self::Email => [
                ['key' => 'email_address', 'label' => 'Email Address', 'type' => 'email', 'sensitive' => false],
                ['key' => 'imap_host', 'label' => 'IMAP Host', 'type' => 'text', 'sensitive' => false],
                ['key' => 'imap_port', 'label' => 'IMAP Port', 'type' => 'number', 'sensitive' => false],
                ['key' => 'imap_username', 'label' => 'IMAP Username', 'type' => 'text', 'sensitive' => false],
                ['key' => 'imap_password', 'label' => 'IMAP Password', 'type' => 'password', 'sensitive' => true],
                ['key' => 'fetch_interval_minutes', 'label' => 'Fetch Interval (minutes)', 'type' => 'number', 'sensitive' => false],
            ],
            self::WebsiteForm => [
                ['key' => 'allowed_domains', 'label' => 'Allowed Domains (comma separated)', 'type' => 'text', 'sensitive' => false],
            ],
        };
    }

    /**
     * Get the config keys that hold sensitive values requiring masking on display.
     *
     * @return array<int, string>
     */
    public function sensitiveConfigKeys(): array
    {
        return array_values(array_map(
            fn (array $field): string => $field['key'],
            array_filter($this->configFields(), fn (array $field): bool => $field['sensitive']),
        ));
    }

    /**
     * Get validation rules for the `config.*` payload of this lead source type.
     *
     * @return array<string, array<int, mixed>>
     */
    public function configValidationRules(bool $isUpdate = false): array
    {
        $sensitiveRule = $isUpdate ? 'nullable' : 'required';

        return match ($this) {
            self::FacebookMessenger => [
                'config.page_id' => ['required', 'string', 'max:255'],
                'config.page_access_token' => [$sensitiveRule, 'string'],
                'config.verify_token' => [$sensitiveRule, 'string'],
                'config.app_secret' => ['nullable', 'string'],
            ],
            self::FacebookLeadAds => [
                'config.page_id' => ['required', 'string', 'max:255'],
                'config.page_access_token' => [$sensitiveRule, 'string'],
                'config.verify_token' => [$sensitiveRule, 'string'],
                'config.app_secret' => ['nullable', 'string'],
            ],
            self::Whatsapp => [
                'config.phone_number_id' => ['required', 'string', 'max:255'],
                'config.access_token' => [$sensitiveRule, 'string'],
                'config.verify_token' => [$sensitiveRule, 'string'],
                'config.app_secret' => ['nullable', 'string'],
            ],
            self::Instagram => [
                'config.auth_mode' => ['required', 'in:facebook,instagram'],
                'config.instagram_account_id' => ['required', 'string', 'max:255'],
                'config.access_token' => [$sensitiveRule, 'string'],
                'config.verify_token' => [$sensitiveRule, 'string'],
                'config.app_secret' => ['nullable', 'string'],
            ],
            self::Email => [
                'config.email_address' => ['required', 'email', 'max:255'],
                'config.imap_host' => ['required', 'string', 'max:255'],
                'config.imap_port' => ['required', 'integer', 'min:1', 'max:65535'],
                'config.imap_username' => ['required', 'string', 'max:255'],
                'config.imap_password' => [$sensitiveRule, 'string'],
                'config.fetch_interval_minutes' => ['nullable', 'integer', 'min:1'],
            ],
            self::WebsiteForm => [
                'config.allowed_domains' => ['nullable', 'string'],
            ],
        };
    }
}
