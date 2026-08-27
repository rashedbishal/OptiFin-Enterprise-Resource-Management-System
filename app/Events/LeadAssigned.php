<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public TenantUser $assignee,
        public ?TenantUser $assignedBy,
    ) {}
}
