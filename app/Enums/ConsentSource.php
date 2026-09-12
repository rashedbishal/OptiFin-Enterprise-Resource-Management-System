<?php

namespace App\Enums;

/**
 * Where a LeadConsent/LeadConsentEvent row's state actually came from.
 * `Merge` is the synthetic-audit-event source App\Services\LeadMergeService
 * writes when reconciling two leads' consent (see mergeConsents()) — never
 * user- or form-originated, so it needs its own value rather than being
 * mislabeled as Manual or WebsiteForm.
 */
enum ConsentSource: string
{
    case WebsiteForm = 'website_form';
    case Manual = 'manual';
    case Merge = 'merge';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::WebsiteForm => 'Website Form',
            self::Manual => 'Manual',
            self::Merge => 'Lead Merge',
        };
    }
}
