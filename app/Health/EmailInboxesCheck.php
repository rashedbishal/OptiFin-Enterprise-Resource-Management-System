<?php

namespace App\Health;

use App\Enums\FailedEmailMessageStatus;
use App\Enums\IntegrationProblem;
use App\Models\FailedEmailMessage;
use App\Services\IntegrationHealth;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Health row "Email inboxes": failing when a client's email inbox can't
 * connect (5 failures in a row — the same rule as the existing IMAP alert),
 * warning when emails failed to import in the last 24 hours.
 */
class EmailInboxesCheck extends Check
{
    use ListsIntegrationProblems;

    public function run(): Result
    {
        $cannotConnect = $this->sourcesWith(app(IntegrationHealth::class)->rows(), [IntegrationProblem::InboxCannotConnect]);

        if ($cannotConnect !== []) {
            return Result::make()
                ->failed(__('These email inboxes can\'t connect: :sources. Check their mailbox settings on the client\'s Lead Sources page.', ['sources' => $this->listed($cannotConnect)]))
                ->shortSummary(trans_choice(':count can\'t connect|:count can\'t connect', count($cannotConnect)));
        }

        $failedToday = FailedEmailMessage::query()
            ->whereIn('status', [FailedEmailMessageStatus::Failed, FailedEmailMessageStatus::Exhausted])
            ->where('first_failed_at', '>=', now()->subDay())
            ->count();

        if ($failedToday > 0) {
            return Result::make()
                ->warning(trans_choice(':count email failed to import in the last 24 hours. It is retried automatically; see Monitoring → Integrations.|:count emails failed to import in the last 24 hours. They are retried automatically; see Monitoring → Integrations.', $failedToday))
                ->shortSummary(trans_choice(':count failed import|:count failed imports', $failedToday));
        }

        return Result::make()->ok()->shortSummary(__('All OK'));
    }
}
