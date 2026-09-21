<?php

namespace App\Services;

use App\Enums\CallRoutingMode;
use App\Enums\TenantUserType;
use App\Models\Lead;
use App\Models\TenantUser;

/**
 * WhatsApp inbound calling — decides who an incoming call is routed to, once,
 * at the moment the LeadCall row is created. Read-only: it never writes, never
 * notifies and never rings anyone (that is a later step). The result is stored
 * on the row as its frozen routing snapshot, so a later reassignment of the
 * lead — including the queued auto-assignment of a brand-new lead — never
 * changes who a call was routed to.
 *
 * Rules:
 *  1. The lead's current assignee, when there is one and they are active and
 *     can take calls → mode Assignee, routed to that one user.
 *  2. Otherwise (unassigned, assignee inactive/without access, or the lead was
 *     created by this very call) → mode AdminFallback, routed to every active
 *     Client Admin of the tenant who can take calls.
 *  3. Otherwise → mode None; the call is recorded but never rings.
 *
 * A lead created by this call always takes rule 2: auto-assignment is a queued
 * listener, so in production the lead is still unassigned at this instant — and
 * treating it as unassigned regardless keeps a synchronous test queue and
 * production agreeing.
 *
 * "Can take calls" is an active user holding the lead-management `update`
 * permission — the same bar the answering step will enforce, so a user who is
 * rung is a user who can actually answer.
 */
class CallRouter
{
    /**
     * @return array{routing_mode: CallRoutingMode, routed_user_ids: array<int, string>, assignee_id_at_arrival: ?string}
     */
    public function route(Lead $lead, bool $leadCreatedByThisCall): array
    {
        $assigneeId = $lead->assigned_to;

        if (! $leadCreatedByThisCall && $assigneeId !== null) {
            $assignee = TenantUser::query()
                ->with('role')
                ->where('tenant_id', $lead->tenant_id)
                ->where('is_active', true)
                ->find($assigneeId);

            if ($assignee instanceof TenantUser && $this->canTakeCalls($assignee)) {
                return [
                    'routing_mode' => CallRoutingMode::Assignee,
                    'routed_user_ids' => [$assignee->id],
                    'assignee_id_at_arrival' => $assigneeId,
                ];
            }
        }

        $adminIds = TenantUser::query()
            ->with('role')
            ->where('tenant_id', $lead->tenant_id)
            ->where('user_type', TenantUserType::ClientAdmin)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (TenantUser $admin): bool => $this->canTakeCalls($admin))
            ->map(fn (TenantUser $admin): string => $admin->id)
            ->values()
            ->all();

        return [
            'routing_mode' => $adminIds === [] ? CallRoutingMode::None : CallRoutingMode::AdminFallback,
            'routed_user_ids' => $adminIds,
            'assignee_id_at_arrival' => $assigneeId,
        ];
    }

    private function canTakeCalls(TenantUser $user): bool
    {
        return $user->hasModulePermission('lead-management', 'update');
    }
}
