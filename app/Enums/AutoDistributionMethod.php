<?php

namespace App\Enums;

enum AutoDistributionMethod: string
{
    case RoundRobin = 'round_robin';
    case LeastLoaded = 'least_loaded';
    case Random = 'random';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
