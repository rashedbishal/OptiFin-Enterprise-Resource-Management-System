<?php

namespace App\Health;

use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Health row "Failed jobs": background jobs that failed in the last hour —
 * warning from the first one, failing at 10 or more.
 */
class FailedJobsCheck extends Check
{
    public const FAIL_AT = 10;

    public function run(): Result
    {
        $count = DB::connection(config('queue.failed.database'))
            ->table(config('queue.failed.table'))
            ->where('failed_at', '>=', now()->subHour())
            ->count();

        $result = Result::make()->meta(['last_hour' => $count])->shortSummary(trans_choice(':count in the last hour|:count in the last hour', $count));

        if ($count >= self::FAIL_AT) {
            return $result->failed(__(':count background jobs failed in the last hour. Review them on Monitoring → Horizon → Failed jobs.', ['count' => $count]));
        }

        if ($count > 0) {
            return $result->warning(trans_choice(':count background job failed in the last hour. Review it on Monitoring → Horizon → Failed jobs.|:count background jobs failed in the last hour. Review them on Monitoring → Horizon → Failed jobs.', $count));
        }

        return $result->ok();
    }
}
