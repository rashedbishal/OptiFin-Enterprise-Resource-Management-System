<?php

namespace App\Enums;

enum LeadDuplicateLinkStatus: string
{
    case Pending = 'pending';
    case Dismissed = 'dismissed';
    case Merged = 'merged';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
