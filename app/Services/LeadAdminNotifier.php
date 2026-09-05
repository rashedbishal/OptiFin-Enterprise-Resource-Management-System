<?php

namespace App\Services;

use App\Enums\TenantUserType;
use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Notifications\Notification;

/**
 * Notifies every other active Client Admin in a lead's tenant about
 * something a user just did to it — never the actor themselves, whether
 * that's an admin or the assigned rep. Shared by Index::saveLead() (a new
 * lead created with a meeting/follow-up already set) and Show's meeting/
 * follow-up create, edit, and cancel actions — extracted here rather than
 * duplicated in both once a second call site needed the exact same rule.
 */
class LeadAdminNotifier
{
    public function notifyExcept(Lead $lead, TenantUser $actor, Notification $notification): void
    {
        TenantUser::query()
            ->where('tenant_id', $lead->tenant_id)
            ->where('user_type', TenantUserType::ClientAdmin)
            ->where('is_active', true)
            ->where('id', '!=', $actor->id)
            ->get()
            ->each(fn (TenantUser $admin) => $admin->notify($notification));
    }
}
