<?php

namespace App\Enums;

/**
 * Feature #13 Phase 1's fixed set of optional, admin-toggleable Lead fields
 * a Website Form may expose — `name` is always implicit and required (Lead
 * itself has no nullable name column, and every existing creation path
 * already treats it as unconditionally required), so it's deliberately not
 * a case here; only the fields an admin can choose to include or omit are.
 */
enum WebsiteFormFixedField: string
{
    case Email = 'email';
    case Phone = 'phone';
    case Message = 'message';

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
            self::Email => 'Email',
            self::Phone => 'Phone',
            self::Message => 'Message',
        };
    }
}
