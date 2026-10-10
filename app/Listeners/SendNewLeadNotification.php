<?php

namespace App\Listeners;

use App\Enums\TenantUserType;
use App\Events\LeadReceived;
use App\Models\TenantUser;
use App\Notifications\NewLeadNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNewLeadNotification implements ShouldQueue
{
    public function handle(LeadReceived $event): void
    {
        $lead = $event->lead;
        $tenant = $lead->tenant;

        $tenant->tenantUsers()
            ->where('user_type', TenantUserType::ClientAdmin)
            ->where('is_active', true)
            ->each(fn ($clientAdmin) => $clientAdmin->notify(new NewLeadNotification($lead)));

        // "Receive unassigned leads" (see LeadSource::unassignedRoles()'s
        // own docblock) — a Client User whose role is granted on this
        // lead's specific source gets the same notification an admin
        // already does, the instant it lands unassigned. A lead created
        // pre-assigned (e.g. a non-admin's own manual entry — see
        // LeadPolicy's docblock on Manual lead entry) never reaches here:
        // assigned_to is already non-null by the time LeadReceived fires.
        if ($lead->assigned_to !== null) {
            return;
        }

        TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_type', TenantUserType::ClientUser)
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->whereHas(
                'unassignedLeadSources',
                fn ($q) => $q->where('lead_sources.id', $lead->source_id),
            ))
            ->each(fn (TenantUser $clientUser) => $clientUser->notify(new NewLeadNotification($lead)));
    }
}
