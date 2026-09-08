<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadScoringService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

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
    public function handle(LeadScoringService $scoringService): int
    {
        $recalculated = 0;

        Lead::query()
            ->whereNull('merged_into_lead_id')
            ->chunkById(200, function ($leads) use ($scoringService, &$recalculated): void {
                foreach ($leads as $lead) {
                    $scoringService->computeFor($lead, 'scheduled_sweep');
                    $recalculated++;
                }
            });

        $this->info("Recalculated score for {$recalculated} lead(s).");

        return self::SUCCESS;
    }
}
