<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadDuplicateDetectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

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
    public function handle(LeadDuplicateDetectionService $detectionService): int
    {
        $scanned = 0;

        Lead::query()
            ->whereNull('merged_into_lead_id')
            ->where(function ($query): void {
                $query->whereNotNull('email')->orWhereNotNull('phone');
            })
            ->select(['id', 'tenant_id', 'email', 'phone', 'merged_into_lead_id'])
            ->chunkById(200, function ($leads) use ($detectionService, &$scanned): void {
                foreach ($leads as $lead) {
                    $detectionService->detectFor($lead);
                    $scanned++;
                }
            });

        $this->info("Scanned {$scanned} lead(s) for possible duplicates.");

        return self::SUCCESS;
    }
}
