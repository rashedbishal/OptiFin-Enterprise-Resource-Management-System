<?php

namespace App\Enums;

use App\Models\LeadSource;

/**
 * What the Platform Admin sees for a WhatsApp source's calling switch, derived
 * from our local intent (`calling_enabled`) and the last status Meta was
 * observed to report (`calling_meta_status`). The two are kept separate on
 * purpose — Meta's reply never silently rewrites our intent — so a disagreement
 * is surfaced as Mismatch rather than papered over.
 */
enum CallingControlState: string
{
    case Off = 'off';
    case On = 'on';
    case Mismatch = 'mismatch';
    case Unverified = 'unverified';

    public static function for(LeadSource $source): self
    {
        $local = (bool) $source->calling_enabled;
        $meta = $source->calling_meta_status;

        if ($meta === null) {
            return $local ? self::Unverified : self::Off;
        }

        return $local === ($meta === 'ENABLED')
            ? ($local ? self::On : self::Off)
            : self::Mismatch;
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => __('Calling off'),
            self::On => __('Calling on'),
            self::Mismatch => __('Calling mismatch'),
            self::Unverified => __('Calling unverified'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Off => 'zinc',
            self::On => 'green',
            self::Mismatch => 'amber',
            self::Unverified => 'sky',
        };
    }
}
