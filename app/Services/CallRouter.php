<?php

namespace App\Services;

use App\Enums\CallRoutingMode;
use App\Enums\CallStatus;
use App\Enums\TenantUserType;
use App\Models\Lead;
use App\Models\LeadCall;
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
 *  1. The lead's current assignee, when there is one and they are active,
 *     can take calls, are not already busy on another active call, and are
 *     currently present at their softphone (WhatsApp Call Backup / Overflow
 *     Receiver — see SoftphonePresence) → mode Assignee, routed to that one
 *     user.
 *  2. Otherwise, when the lead has an assignee (WhatsApp Call Backup /
 *     Overflow Receiver): every active TenantUser of the same tenant with
 *     is_whatsapp_backup_receiver=true who can take calls and is not
 *     themselves busy, excluding the assignee → mode BackupFallback, routed
 *     to all of them simultaneously (same fan-out shape as AdminFallback
 *     below — the existing compare-and-set claim decides who actually
 *     answers). Never touches Lead.assigned_to; this is call-level overflow
 *     coverage only. A lead with no assignee never reaches this rule.
 *  3. Otherwise (unassigned, assignee unusable with no eligible backup
 *     either, or the lead was created by this very call) → mode
 *     AdminFallback, routed to every active Client Admin of the tenant who
 *     can take calls, UNIONED with every active TenantUser whose role has
 *     "receive unassigned leads" access (see LeadSource::unassignedRoles())
 *     to this call's specific lead_source_id and is not already busy — same
 *     simultaneous fan-out as rule 2, same reasoning: an unassigned lead's
 *     call should ring everyone who already has standing visibility/working
 *     rights on it, not just admins. The stored routing_mode stays
 *     AdminFallback regardless of which of the two pools actually populated
 *     routed_user_ids (including both, or neither admins exist but
 *     role-receivers do) — it is not a claim that every routed id is an
 *     admin, only that this is the lead-has-no-usable-assignee tier.
 *  4. Otherwise → mode None; the call is recorded but never rings.
 *
 * A lead created by this call always skips rules 1-2 straight to rule 3:
 * auto-assignment is a queued listener, so in production the lead is still
 * unassigned at this instant — and treating it as unassigned regardless keeps
 * a synchronous test queue and production agreeing.
 *
 * "Can take calls" is an active user holding the lead-management `update`
 * permission — the same bar the answering step will enforce, so a user who is
 * rung is a user who can actually answer. "Busy" (rules 1-2 only) is an
 * already-claimed Answering/Connected LeadCall — the one concrete signal this
 * app has for "currently occupied," deliberately not the broader `active()`
 * scope (which also includes Ringing/Initiated, states a candidate can be
 * routed into without yet being claimed). Beyond that there is still no
 * general online/offline presence system in this app, and this router does
 * not add one — the one narrow exception (rule 1 only) is SoftphonePresence,
 * which tells whether the ASSIGNEE specifically still has a softphone tab
 * open, so an assignee who is otherwise eligible but not actually reachable
 * falls through to rule 2 instead of soaking up every call themselves.
 * Backup and admin candidates are deliberately never presence-checked — see
 * eligibleBackupReceivers()'s own note. A mid-ring change in a routed user's
 * eligibility (including presence) never re-routes an already-ringing call;
 * routing is frozen at this method's return.
 */
class CallRouter
{
    public function __construct(private readonly SoftphonePresence $presence) {}

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

            if ($assignee instanceof TenantUser && $this->canTakeCalls($assignee) && ! $this->isBusy($assignee) && $this->presence->isAvailable($assignee)) {
                return [
                    'routing_mode' => CallRoutingMode::Assignee,
                    'routed_user_ids' => [$assignee->id],
                    'assignee_id_at_arrival' => $assigneeId,
                ];
            }

            $backupIds = $this->eligibleBackupReceivers($lead->tenant_id, $assigneeId);

            if ($backupIds !== []) {
                return [
                    'routing_mode' => CallRoutingMode::BackupFallback,
                    'routed_user_ids' => $backupIds,
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

        // Sorted, not just de-duped: each pool arrives individually sorted
        // by id (both queries end in orderBy('id')) but their UNION isn't
        // globally sorted by concatenation alone — every other multi-
        // candidate routing_mode in this class returns ascending id order
        // (see BackupFallback/AdminFallback's own tests), so this merge
        // must too.
        $routedIds = array_unique([...$this->eligibleUnassignedRoleReceivers($lead), ...$adminIds]);
        sort($routedIds);

        return [
            'routing_mode' => $routedIds === [] ? CallRoutingMode::None : CallRoutingMode::AdminFallback,
            'routed_user_ids' => $routedIds,
            'assignee_id_at_arrival' => $assigneeId,
        ];
    }

    /**
     * "Receive unassigned leads" (see LeadSource::unassignedRoles()'s own
     * docblock) — every active, call-capable, non-busy TenantUser whose
     * role has been granted access to this specific call's lead_source_id.
     * Same batched-busy-check shape as eligibleBackupReceivers() below, for
     * the same N+1 reason.
     *
     * @return array<int, string>
     */
    private function eligibleUnassignedRoleReceivers(Lead $lead): array
    {
        $candidates = TenantUser::query()
            ->with('role')
            ->where('tenant_id', $lead->tenant_id)
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->whereHas(
                'unassignedLeadSources',
                fn ($q) => $q->where('lead_sources.id', $lead->source_id),
            ))
            ->orderBy('id')
            ->get()
            ->filter(fn (TenantUser $candidate): bool => $this->canTakeCalls($candidate));

        if ($candidates->isEmpty()) {
            return [];
        }

        $busyIds = $this->busyUserIds($lead->tenant_id, $candidates->pluck('id')->all());

        return $candidates
            ->reject(fn (TenantUser $candidate): bool => in_array($candidate->id, $busyIds, true))
            ->map(fn (TenantUser $candidate): string => $candidate->id)
            ->values()
            ->all();
    }

    private function canTakeCalls(TenantUser $user): bool
    {
        return $user->hasModulePermission('lead-management', 'update');
    }

    /**
     * WhatsApp Call Backup / Overflow Receiver — every active, permitted,
     * non-busy backup-flagged user of the tenant, excluding the assignee
     * themselves (rule 14 of the approved design: the assignee is never also
     * their own backup candidate — they were already tried, and failed, in
     * rule 1 above). Same query shape as the AdminFallback block below,
     * deliberately, for the same reasons: tenant-scoped, `orderBy('id')` for
     * deterministic output, filtered by the same canTakeCalls() bar.
     *
     * The busy check is batched (busyUserIds(), one query for every
     * candidate together) rather than calling isBusy() per candidate in the
     * filter below — Phase 7 audit finding: the original per-candidate form
     * ran one lead_calls query per backup candidate (confirmed: 5
     * candidates → 5 queries), an N+1 this method's own candidate count can
     * scale with. Behavior is unchanged; only the query shape is batched.
     *
     * Deliberately NOT presence-checked: opting in to is_whatsapp_backup_
     * receiver is itself the signal this user is willing to take overflow
     * calls, unlike an assignee who never opted into being reachable at all
     * times. A backup receiver's own softphone tab still has to be open to
     * actually see the ring (same as every other routed user, assignee
     * included — SoftphonePresence only ever changes whether rule 1 fires,
     * never who is eligible under rule 2), but they are never excluded from
     * routed_user_ids for being presence-stale the way an assignee is.
     *
     * @return array<int, string>
     */
    private function eligibleBackupReceivers(string $tenantId, ?string $excludeAssigneeId): array
    {
        $candidates = TenantUser::query()
            ->with('role')
            ->where('tenant_id', $tenantId)
            ->where('is_whatsapp_backup_receiver', true)
            ->where('is_active', true)
            ->when($excludeAssigneeId !== null, fn ($query) => $query->where('id', '!=', $excludeAssigneeId))
            ->orderBy('id')
            ->get()
            ->filter(fn (TenantUser $candidate): bool => $this->canTakeCalls($candidate));

        if ($candidates->isEmpty()) {
            return [];
        }

        $busyIds = $this->busyUserIds($tenantId, $candidates->pluck('id')->all());

        return $candidates
            ->reject(fn (TenantUser $candidate): bool => in_array($candidate->id, $busyIds, true))
            ->map(fn (TenantUser $candidate): string => $candidate->id)
            ->values()
            ->all();
    }

    /**
     * "Busy" = this user already owns (claimed_by) another LeadCall of this
     * same tenant that is Answering or Connected right now — a real signal
     * built entirely from data this app already records, not a presence
     * system. Deliberately narrower than LeadCall::active() (which also
     * matches Ringing/Initiated, neither of which implies this specific user
     * has actually claimed anything yet).
     */
    private function isBusy(TenantUser $user): bool
    {
        return LeadCall::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('claimed_by', $user->id)
            ->whereIn('status', [CallStatus::Answering, CallStatus::Connected])
            ->exists();
    }

    /**
     * The batched form of isBusy() for a whole candidate set at once — same
     * "claimed_by + Answering/Connected" definition, one query regardless of
     * how many candidate ids are passed.
     *
     * @param  array<int, string>  $candidateIds
     * @return array<int, string>
     */
    private function busyUserIds(string $tenantId, array $candidateIds): array
    {
        return LeadCall::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('claimed_by', $candidateIds)
            ->whereIn('status', [CallStatus::Answering, CallStatus::Connected])
            ->pluck('claimed_by')
            ->unique()
            ->all();
    }
}
