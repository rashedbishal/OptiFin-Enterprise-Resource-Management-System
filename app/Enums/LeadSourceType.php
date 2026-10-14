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
    case LinkedInLeadGen = 'linkedin_lead_gen';
    // Not a Platform-Admin-configured external channel like the others -
    // the single per-tenant source every manually keyed-in lead (see
    // App\Actions\Tenant\CreateManualLead) is attributed to, lazily
    // created on first use. Excluded from
    // Platform\Clients\LeadSources::typeOptions() for that reason.
    case Manual = 'manual';
    // Same lazily-provisioned, non-Platform-Admin-configured shape as
    // Manual above (see App\Actions\Tenant\CreateLeadFromImport) — every
    // Lead created via Feature #7's CSV Import is attributed to the
    // tenant's single CsvImport source, not Manual, so imported leads stay
    // distinguishable from phone-call/walk-in leads in the Source filter
    // and badges. Also excluded from
    // Platform\Clients\LeadSources::typeOptions().
    case CsvImport = 'csv_import';

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
            self::LinkedInLeadGen => 'LinkedIn Lead Gen',
            self::Manual => 'Custom',
            self::CsvImport => 'CSV Import',
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
            self::LinkedInLeadGen => 'briefcase',
            self::Manual => 'pencil-square',
            self::CsvImport => 'table-cells',
        };
    }

    /**
     * Whether a Lead attributed to this source type may be deleted
     * individually (single-lead delete, from the table row or the lead
     * detail/panel view) — only self-created/tenant-owned data (a manually
     * keyed-in lead, a submitted website form, an IMAP-polled email) is
     * eligible. Every Platform-Admin-configured external/automated channel
     * (Messenger, Facebook Lead Ads, WhatsApp, Instagram, LinkedIn) and CSV
     * Import stay individually undeletable — the same rule already enforced
     * by Index::performBulkDelete() for bulk deletion.
     */
    public function isIndividuallyDeletable(): bool
    {
        return match ($this) {
            self::Email, self::WebsiteForm, self::Manual => true,
            default => false,
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
                ['key' => 'whatsapp_business_account_id', 'label' => 'WhatsApp Business Account ID (enables automatic webhook subscription on Test Connection)', 'type' => 'text', 'sensitive' => false],
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
                // Optional: enforced via the submitting request's Origin/
                // Referer header (see LeadWebhookController::
                // assertAllowedWebsiteFormDomain()) only once at least one
                // domain is set here — left blank, the API key alone
                // authenticates the submission, which is required anyway
                // for a purely server-to-server integration that never
                // sends either header.
                ['key' => 'allowed_domains', 'label' => 'Allowed Domains (comma separated, optional — restricts which sites may submit)', 'type' => 'text', 'sensitive' => false],
            ],
            // No `app_secret` field here, unlike the Meta-family types above:
            // LinkedIn webhook signatures are keyed by this app's single
            // Developer App client secret (config('services.linkedin')),
            // not a per-tenant secret — there is only ever one LinkedIn App
            // for the whole ERP, shared across every tenant's connection.
            self::LinkedInLeadGen => [
                // Typed by hand for now. Once OAuth (Phase 2) can enumerate
                // the orgs/ad accounts the connecting member actually has
                // Lead Sync roles on, this should become a discovered
                // choice instead of free text — kept a plain text field
                // today so a source is still fully configurable before
                // OAuth exists.
                ['key' => 'organization_urn', 'label' => 'Organization / Sponsored Account URN', 'type' => 'text', 'sensitive' => false],
                // The options below are LinkedIn's own leadType enum shape,
                // not a claim about what this tenant is actually permitted
                // to sync — that's only knowable from the API/OAuth grant
                // itself (Phase 2/3), and must stay the source of truth for
                // what a given connection can access.
                ['key' => 'lead_type', 'label' => 'Lead Type', 'type' => 'select', 'sensitive' => false, 'options' => [
                    'SPONSORED' => 'Sponsored (Ads)',
                    'EVENT' => 'Event',
                    'COMPANY' => 'Company Page',
                    'ORGANIZATION_PRODUCT' => 'Organization Product',
                ]],
                // Populated by the OAuth callback (Phase 2), not typed here
                // — see configValidationRules() for why these stay nullable
                // even at creation time.
                ['key' => 'access_token', 'label' => 'Access Token (set automatically via Connect with LinkedIn)', 'type' => 'password', 'sensitive' => true],
                ['key' => 'refresh_token', 'label' => 'Refresh Token (set automatically via Connect with LinkedIn)', 'type' => 'password', 'sensitive' => true],
                ['key' => 'fetch_interval_minutes', 'label' => 'Recovery Poll Interval (minutes)', 'type' => 'number', 'sensitive' => false],
            ],
            // Nothing to configure - see the case's own doc comment above.
            self::Manual => [],
            // Same reasoning as Manual above.
            self::CsvImport => [],
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
                'config.whatsapp_business_account_id' => ['nullable', 'string', 'max:255'],
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
            self::LinkedInLeadGen => [
                'config.organization_urn' => ['required', 'string', 'starts_with:urn:li:', 'max:255'],
                'config.lead_type' => ['required', 'in:SPONSORED,EVENT,COMPANY,ORGANIZATION_PRODUCT'],
                // Always nullable, regardless of $isUpdate: unlike every
                // other type's tokens, these can't be typed in by an admin
                // in the normal case — they only arrive via the OAuth
                // callback (Phase 2). A source must be creatable, and
                // editable, before it's connected.
                'config.access_token' => ['nullable', 'string'],
                'config.refresh_token' => ['nullable', 'string'],
                'config.fetch_interval_minutes' => ['nullable', 'integer', 'min:1'],
            ],
            self::Manual => [],
            self::CsvImport => [],
        };
    }
}
