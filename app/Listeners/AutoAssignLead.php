<?php

namespace App\Listeners;

use App\Events\LeadReceived;
use App\Services\LeadAssignmentService;
use Illuminate\Contracts\Queue\ShouldQueue;

class AutoAssignLead implements ShouldQueue
{
    public function __construct(private readonly LeadAssignmentService $assignmentService) {}

    public function handle(LeadReceived $event): void
    {
        $this->assignmentService->autoAssign($event->lead);
    }
}
