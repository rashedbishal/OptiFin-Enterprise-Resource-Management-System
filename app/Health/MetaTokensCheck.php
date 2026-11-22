<?php

namespace App\Health;

use App\Enums\IntegrationProblem;
use App\Services\IntegrationHealth;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Health row "Meta tokens": failing when any active client's Meta token is
 * invalid or missing, warning when one expires within 7 days. Same rules as
 * Admin → Monitoring → Integrations (IntegrationHealth), which lists them.
 */
class MetaTokensCheck extends Check
{
    use ListsIntegrationProblems;

    public function run(): Result
    {
        $rows = app(IntegrationHealth::class)->rows();
        $broken = $this->sourcesWith($rows, [IntegrationProblem::TokenInvalid, IntegrationProblem::TokenMissing]);
        $expiring = $this->sourcesWith($rows, [IntegrationProblem::TokenExpiresSoon]);

        if ($broken !== []) {
            return Result::make()
                ->failed(__('Meta tokens not working for: :sources. Fix them on Monitoring → Integrations.', ['sources' => $this->listed($broken)]))
                ->shortSummary(trans_choice(':count not working|:count not working', count($broken)));
        }

        if ($expiring !== []) {
            return Result::make()
                ->warning(__('Meta tokens expiring within 7 days: :sources. Renew them before they stop working.', ['sources' => $this->listed($expiring)]))
                ->shortSummary(trans_choice(':count expiring soon|:count expiring soon', count($expiring)));
        }

        return Result::make()->ok()->shortSummary(__('All OK'));
    }
}
