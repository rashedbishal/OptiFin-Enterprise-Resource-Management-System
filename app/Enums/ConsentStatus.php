<?php

namespace App\Enums;

/**
 * A lead's consent state for a given ConsentType. Deliberately only two
 * values — "never provided" is represented by the absence of a
 * App\Models\LeadConsent row entirely, not a third status here, the same
 * "no row and blank are the same state, never two" discipline
 * LeadCustomFieldValue already established.
 */
enum ConsentStatus: string
{
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';

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
            self::Granted => 'Granted',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
