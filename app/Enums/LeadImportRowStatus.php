<?php

namespace App\Enums;

/**
 * The outcome of one CSV data row within a LeadImport — written exactly
 * once per row_number by App\Actions\Tenant\CreateLeadFromImport, never
 * updated afterward (a row's result is a permanent, point-in-time record,
 * same principle as LeadScoreLog's breakdown snapshot).
 */
enum LeadImportRowStatus: string
{
    case Imported = 'imported';
    case Duplicate = 'duplicate';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Imported => 'Imported',
            self::Duplicate => 'Duplicate',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Imported => 'emerald',
            self::Duplicate => 'amber',
            self::Failed => 'red',
        };
    }
}
