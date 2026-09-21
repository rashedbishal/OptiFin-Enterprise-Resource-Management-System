<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadCall;
use App\Support\EligibilityResult;

/**
 * PROD-1 — decides whether a Lead may be hard-deleted, on business STATE
 * alone. Authorization (Gate 'delete'), the individual-deletion source-type
 * restriction and the password confirmation all stay exactly where they were
 * at every call site; this only adds the merged-lead rule, after them.
 *
 * A merged cluster is permanently protected — deliberately no escape hatch
 * and no unmerge:
 *   - a merged LOSER (merged_into_lead_id set) is retired, never deleted (the
 *     merge design has always said so — see LeadMergeService), and
 *   - a SURVIVOR with at least one merged loser cannot be deleted either,
 *     because deleting it would silently turn its hidden losers back into
 *     ordinary visible leads while destroying the history reparented onto it.
 * Both predicates are direct: any lead inside a merge chain (A→B→C) is either
 * itself a loser or has a direct loser, so no transitive traversal is needed.
 *
 * evaluate() is authoritative only when called inside the caller's deletion
 * transaction: it re-fetches the target row FOR UPDATE first, so a merge
 * committing concurrently (LeadMergeService locks the survivor and loser rows
 * together before it writes merged_into_lead_id, its only writer) is either
 * fully visible here or waits behind this lock. That lock is the ONLY lead
 * lock taken — the loser lookup below is a plain read — so DB-1's single-lock
 * audit invariant and LeadMergeService's ordered two-row lock are untouched.
 * The advisory form ($lock = false) is for opening a confirmation early and
 * must never be relied on for correctness.
 */
class LeadDeletionEligibilityService
{
    /**
     * Same wording UX-1's Show mutation guard already uses for a merged lead.
     */
    public static function loserReason(): string
    {
        return __('This lead has been merged and can no longer be changed.');
    }

    public static function survivorReason(): string
    {
        return __('This lead has merged leads and can no longer be deleted.');
    }

    public static function activeCallReason(): string
    {
        return __('This lead has a call in progress and can\'t be deleted until it ends.');
    }

    public function evaluate(Lead $lead, bool $lock = true): EligibilityResult
    {
        $query = Lead::where('tenant_id', $lead->tenant_id)->whereKey($lead->id);

        $current = ($lock ? $query->lockForUpdate() : $query)->first();

        // Already gone: nothing to protect. Left eligible on purpose so the
        // existing "already gone" handling in RecordLeadDeletionAudit (which
        // returns null for a missing row) stays the single owner of that case.
        if ($current === null) {
            return EligibilityResult::eligible();
        }

        if ($current->merged_into_lead_id !== null) {
            return EligibilityResult::ineligible(self::loserReason());
        }

        if (Lead::where('tenant_id', $current->tenant_id)->where('merged_into_lead_id', $current->id)->exists()) {
            return EligibilityResult::ineligible(self::survivorReason());
        }

        // WhatsApp calling — deleting the lead mid-call would cascade the
        // live call row away, leaving the agent's call unmanageable and a
        // late Meta terminate webhook nothing to attach to. Terminal calls
        // never block: they are deleted with the lead like any other child.
        if (LeadCall::where('tenant_id', $current->tenant_id)->where('lead_id', $current->id)->active()->exists()) {
            return EligibilityResult::ineligible(self::activeCallReason());
        }

        return EligibilityResult::eligible();
    }
}
