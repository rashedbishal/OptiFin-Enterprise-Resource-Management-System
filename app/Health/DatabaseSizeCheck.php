<?php

namespace App\Health;

use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Health row "Database size" (PostgreSQL): warning above 2 GB, failing above
 * 10 GB. The package's own DatabaseSizeCheck only has a failure threshold.
 * Above ~2 GB the nightly backups also fill Backblaze B2's free 10 GB faster
 * (cleanup then keeps fewer old backups to stay under the limit).
 */
class DatabaseSizeCheck extends Check
{
    public const WARN_ABOVE_GB = 2;

    public const FAIL_ABOVE_GB = 10;

    public function run(): Result
    {
        $gigabytes = round($this->sizeInBytes() / 1024 ** 3, 2);
        $result = Result::make()->meta(['size_gb' => $gigabytes])->shortSummary($gigabytes.' GB');

        if ($gigabytes > self::FAIL_ABOVE_GB) {
            return $result->failed(__('The database is :size GB, above the :limit GB limit.', ['size' => $gigabytes, 'limit' => self::FAIL_ABOVE_GB]));
        }

        if ($gigabytes > self::WARN_ABOVE_GB) {
            return $result->warning(__('The database is :size GB, above :limit GB. Backups grow with it and fill Backblaze B2\'s free 10 GB faster.', ['size' => $gigabytes, 'limit' => self::WARN_ABOVE_GB]));
        }

        return $result->ok();
    }

    protected function sizeInBytes(): int
    {
        return (int) DB::selectOne('select pg_database_size(current_database()) as size')->size;
    }
}
