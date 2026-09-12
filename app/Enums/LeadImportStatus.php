<?php

namespace App\Enums;

/**
 * The lifecycle of one CSV import batch (App\Models\LeadImport). Set to
 * Processing when the first chunk job starts, and only ever moved to a
 * terminal state (Completed/CompletedWithErrors/Failed) by
 * App\Imports\LeadCsvImport's AfterImport listener (normal completion) or
 * its failed() hook (a chunk job's chain aborted on an infrastructure
 * fault) — see that class for why both paths are needed.
 */
enum LeadImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with errors',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Processing => 'blue',
            self::Completed => 'emerald',
            self::CompletedWithErrors => 'amber',
            self::Failed => 'red',
        };
    }
}
