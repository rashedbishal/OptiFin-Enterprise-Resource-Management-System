<?php

namespace App\Enums;

/**
 * Feature #14 Phase 1's fixed set of tracked consent dimensions — deliberately
 * only two, independently tracked and never blended into one another (a lead
 * can be Privacy-granted but Marketing-withdrawn, or any other combination).
 * A plain, engineering-controlled PHP enum, matching DealStatus/TaskStatus/
 * WebsiteFormFieldType's precedent — not a tenant-configurable catalog like
 * LeadStatus/Currency, since a tenant does not get to invent its own consent
 * types in Phase 1.
 */
enum ConsentType: string
{
    case Privacy = 'privacy';
    case Marketing = 'marketing';

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
            self::Privacy => 'Privacy / Data Processing Consent',
            self::Marketing => 'Marketing / Communication Consent',
        };
    }
}
