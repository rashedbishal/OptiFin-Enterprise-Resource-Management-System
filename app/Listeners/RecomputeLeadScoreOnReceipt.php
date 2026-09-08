<?php

namespace App\Listeners;

use App\Events\LeadReceived;
use App\Services\LeadScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Computes a newly received lead's initial score — additive alongside
 * AutoAssignLead/SendNewLeadNotification, which also listen on LeadReceived
 * and are untouched by this. Manually-created leads don't fire LeadReceived
 * at all (a pre-existing, unrelated gap — see App\Actions\Tenant\CreateManualLead),
 * so that action calls LeadScoringService directly instead of relying on
 * this listener.
 */
class RecomputeLeadScoreOnReceipt implements ShouldQueue
{
    public function __construct(private readonly LeadScoringService $scoringService) {}

    public function handle(LeadReceived $event): void
    {
        $this->scoringService->computeFor($event->lead, 'lead_received');
    }
}
