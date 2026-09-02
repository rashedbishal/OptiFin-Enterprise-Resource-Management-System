<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\TenantUser;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public LeadStatus $oldStatus,
        public LeadStatus $newStatus,
        public TenantUser $changedBy,
    ) {}
}
