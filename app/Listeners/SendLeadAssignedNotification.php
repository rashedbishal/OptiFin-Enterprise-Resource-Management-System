<?php

namespace App\Listeners;

use App\Events\LeadAssigned;
use App\Notifications\LeadAssignedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendLeadAssignedNotification implements ShouldQueue
{
    public function handle(LeadAssigned $event): void
    {
        $event->assignee->notify(new LeadAssignedNotification($event->lead, $event->assignedBy));
    }
}
