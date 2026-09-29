<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadScoringService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily safety-net sweep for Lead Scoring: App\Services\LeadScoringService
 * is otherwise only invoked from targeted event/detail-change hooks (see
 * LeadScoringService::SCORING_RELEVANT_DETAIL_FIELDS and its callers), none
 * of which re-fire purely because time has passed — this is the only thing
 * that re-evaluates time-derived fields (days_since_created,
 * days_since_last_status_change) for a lead nobody has touched recently.
 * Excludes merged-away leads (see App\Services\LeadMergeService) — a
 * retired lead has nothing left to score for.
 */
#[Signature('leads:recalculate-scores')]
#[Description('Recompute every lead\'s score from its tenant\'s current active scoring rules')]
class RecalculateLeadScores extends Command
{
    /**
     * F12 remediation (forensic audit Batch C) — a per-lead try/catch,
     * mirroring NurtureInactiveLeads::processLead()'s identical isolation
     * pattern (the established precedent for this exact "one bad row must
     * not abort the rest of the sweep" shape in this codebase): a single
     * lead throwing (a transient DB error, a malformed scoring rule value
     * not already caught by LeadScoringService::evaluateRule()'s own
     * internal guard) is logged with enough context to find it again and
     * the sweep continues — never left to abort the whole chunk/run, and
     * never silently swallowed. No retry bookkeeping is needed the way
     * ProcessEmailLeadSource's FailedEmailMessage table provides for IMAP:
     * LeadScoringService::computeFor() always recomputes a lead's score
     * from scratch on every call, so a lead that failed today is simply
     * attempted again, for free, on tomorrow's run.
     */
    public function handle(LeadScoringService $scoringService): int
    {
        $recalculated = 0;
        $failed = 0;

        Lead::query()
            ->whereNull('merged_into_lead_id')
            ->chunkById(200, function ($leads) use ($scoringService, &$recalculated, &$failed): void {
                foreach ($leads as $lead) {
                    try {
                        $scoringService->computeFor($lead, 'scheduled_sweep');
                        $recalculated++;
                    } catch (Throwable $e) {
                        $failed++;

                        Log::error("leads:recalculate-scores: failed to recalculate score for lead [{$lead->id}] (tenant [{$lead->tenant_id}]): {$e->getMessage()}");
                    }
                }
            });

        $this->info("Recalculated score for {$recalculated} lead(s).");

        // A summary, structural signal — never per-item noise — so a
        // PATTERN of failures (many leads failing, suggesting a systemic
        // problem rather than one flaky row) stays operator-visible
        // without every single transient failure independently paging
        // anyone, mirroring the same two-tier severity approach
        // App\Http\Middleware\ThrottleRequestsFailOpen already uses
        // elsewhere in this codebase (per-event warning, structural
        // error). Deliberately does not change this command's own exit
        // code: $monitored()'s own onFailure() alerting exists for a
        // whole-command failure, not isolated per-lead ones — see
        // routes/console.php's own docblock.
        if ($failed > 0) {
            Log::warning("leads:recalculate-scores completed with {$failed} of ".($recalculated + $failed).' lead(s) failing.');
        }

        return self::SUCCESS;
    }
}
