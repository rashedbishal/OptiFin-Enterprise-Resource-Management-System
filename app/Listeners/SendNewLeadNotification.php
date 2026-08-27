<?php

namespace App\Listeners;

use App\Enums\TenantUserType;
use App\Events\LeadReceived;
use App\Notifications\NewLeadNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNewLeadNotification implements ShouldQueue
{
    public function handle(LeadReceived $event): void
    {
        $tenant = $event->lead->tenant;

        $tenant->tenantUsers()
            ->where('user_type', TenantUserType::ClientAdmin)
            ->where('is_active', true)
            ->each(fn ($clientAdmin) => $clientAdmin->notify(new NewLeadNotification($event->lead)));
    }
}
