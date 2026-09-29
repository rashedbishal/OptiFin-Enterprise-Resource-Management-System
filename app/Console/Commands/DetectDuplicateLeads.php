<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadDuplicateDetectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backfill/sweep pass for Duplicate Detection: App\Livewire\Tenant\Leads\Show
 * already runs detection live whenever a lead's detail page is opened, so
 * this command exists only to catch pairs nobody has viewed yet (e.g. two
 * leads that both arrived via automated channels and were never opened),
 * and to pick up matches created by a bulk import or backfill.
 */
#[Signature('leads:detect-duplicates')]
#[Description('Scan every tenant\'s leads for possible cross-channel duplicates')]
class DetectDuplicateLeads extends Command
{
    /**
     * F12 remediation (forensic audit Batch C) — see
     * RecalculateLeadScores::handle()'s identical comment for the full
     * reasoning; this command shares the exact same shape of fix. No
     * retry bookkeeping is needed here either:
     * LeadDuplicateDetectionService::detectFor() is itself idempotent
     * (race-safe create, re-running it for the same lead is always safe),
     * so a lead that failed today is simply re-scanned, for free, on
     * tomorrow's run.
     */
    public function handle(LeadDuplicateDetectionService $detectionService): int
    {
        $scanned = 0;
        $failed = 0;

        Lead::query()
            ->whereNull('merged_into_lead_id')
            ->where(function ($query): void {
                $query->whereNotNull('email')->orWhereNotNull('phone');
            })
            ->select(['id', 'tenant_id', 'email', 'phone', 'merged_into_lead_id'])
            ->chunkById(200, function ($leads) use ($detectionService, &$scanned, &$failed): void {
                foreach ($leads as $lead) {
                    try {
                        $detectionService->detectFor($lead);
                        $scanned++;
                    } catch (Throwable $e) {
                        $failed++;

                        Log::error("leads:detect-duplicates: failed to scan lead [{$lead->id}] (tenant [{$lead->tenant_id}]) for duplicates: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Scanned {$scanned} lead(s) for possible duplicates.");

        // See RecalculateLeadScores::handle()'s identical summary-log
        // comment for the full reasoning — a structural signal only, never
        // per-item noise, and never a changed exit code.
        if ($failed > 0) {
            Log::warning("leads:detect-duplicates completed with {$failed} of ".($scanned + $failed).' lead(s) failing.');
        }

        return self::SUCCESS;
    }
}
