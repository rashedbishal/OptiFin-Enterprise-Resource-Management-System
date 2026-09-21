<?php

namespace App\Policies;

use App\Models\LeadCall;
use App\Models\TenantUser;

/**
 * WhatsApp inbound calling — who may act on a live call (claim, answer,
 * accept, reject). The bar is exactly the one CallRouter rang the user by: an
 * active user of the call's tenant holding the lead-management `update`
 * permission, AND named in the call's frozen `routed_user_ids` snapshot. It is
 * deliberately not LeadPolicy::update() — a later reassignment of the lead
 * must never change who may answer a call that already rang someone else, and
 * a Client Admin outside the snapshot was never rung.
 *
 * Which of those users may take a given STEP (only the claimant may answer,
 * accept, or reject a claimed call) depends on the call's current state and is
 * enforced by the actions, against a fresh read.
 */
class LeadCallPolicy
{
    public function answer(TenantUser $user, LeadCall $call): bool
    {
        return $user->tenant_id === $call->tenant_id
            && $user->is_active
            && $user->hasModulePermission('lead-management', 'update')
            && in_array($user->id, $call->routed_user_ids ?? [], true);
    }
}
