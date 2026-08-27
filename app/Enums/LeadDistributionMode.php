<?php

namespace App\Enums;

enum LeadDistributionMode: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
