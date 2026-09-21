<?php

namespace App\Actions\Tenant;

use App\Models\Lead;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use App\Models\LeadDeletionAudit;
use App\Models\LeadScoreLog;
use App\Models\TenantUser;

/**
 * DB-1 — the single primitive every Lead-deletion call site
 * (Index::delete(), Index::performBulkDelete(), Show::delete()) goes
 * through immediately before $lead->delete() itself, inside the SAME
 * DB::transaction as that delete — never a separate one, so a partial
 * outcome (audit without delete, or delete without audit) is never
 * possible. Two things happen here, both while the Lead row still exists:
 *
 *   1. A permanent LeadDeletionAudit row is created — the only trace of a
 *      deleted Lead's identity that survives it, since Leads have no
 *      SoftDeletes and never will.
 *   2. This Lead's lead_consents/lead_consent_events rows (which
 *      nullOnDelete rather than cascade — see those tables' migrations)
 *      are stamped with lead_identifier/lead_name so they stay
 *      identifiable once lead_id goes NULL a moment later.
 *
 * Deliberately no try/catch here: if creating the audit row or snapshotting
 * consent identity throws for any reason, that exception propagates straight
 * out to the caller's DB::transaction and rolls it back — the Lead is then
 * never deleted (fail-closed). Swallowing that failure here would be exactly
 * the "custom failure-swallowing catch" the approved DB-1 architecture rules
 * out.
 *
 * Concurrency (DB-1 follow-up): the very first thing handle() does is
 * re-fetch this exact Lead row with lockForUpdate(), inside the caller's
 * transaction — never trusting the possibly-stale, pre-transaction $lead
 * instance passed in. This closes a race where two concurrent delete
 * requests for the same Lead (a double-click, two tabs, a client retry)
 * could otherwise both pass authorization on their own independently-loaded
 * Lead object and both reach this method, producing two durable audit rows
 * for one physical deletion. Whichever request's transaction acquires the
 * lock first proceeds normally; the other blocks, and once the first
 * commits (deleting the row), the second's locked re-fetch finds nothing
 * and returns null — no audit, no consent snapshot, nothing written. The
 * caller must treat a null return as "already gone" and skip $lead->delete()
 * — never a second attempt at any of this method's own writes. Same idiom
 * already used throughout this codebase for this exact shape of problem
 * (Lead::nextIdentifier(), CreateManualLead, LeadSources::toggle*(),
 * LeadMergeService::merge()'s own lockForUpdate() guard).
 */
class RecordLeadDeletionAudit
{
    public function handle(Lead $lead, ?TenantUser $deletedBy, bool $isBulk): ?LeadDeletionAudit
    {
        $lockedLead = Lead::where('tenant_id', $lead->tenant_id)
            ->whereKey($lead->id)
            ->lockForUpdate()
            ->first();

        if ($lockedLead === null) {
            return null;
        }

        $audit = $this->createAudit($lockedLead, $deletedBy, $isBulk);

        $this->snapshotConsentIdentity($lockedLead);

        return $audit;
    }

    /**
     * Split out from handle() only so a failure here (the audit write) and
     * a failure in snapshotConsentIdentity() below (the consent snapshot)
     * are independently exercisable in tests — both still run inside the
     * one caller-owned DB::transaction either way, so either one throwing
     * rolls back everything, including an audit row already written by this
     * method in the same transaction.
     */
    protected function createAudit(Lead $lead, ?TenantUser $deletedBy, bool $isBulk): LeadDeletionAudit
    {
        return LeadDeletionAudit::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'lead_identifier' => $lead->identifier,
            'lead_name' => $lead->name,
            'lead_email' => $lead->email,
            'lead_phone' => $lead->phone,
            'source_type' => $lead->source->type->value,
            'deleted_by' => $deletedBy?->id,
            'is_bulk' => $isBulk,
            'cascade_summary' => $this->cascadeSummary($lead),
        ]);
    }

    protected function snapshotConsentIdentity(Lead $lead): void
    {
        LeadConsent::where('lead_id', $lead->id)->update([
            'lead_identifier' => $lead->identifier,
            'lead_name' => $lead->name,
        ]);

        LeadConsentEvent::where('lead_id', $lead->id)->update([
            'lead_identifier' => $lead->identifier,
            'lead_name' => $lead->name,
        ]);
    }

    /**
     * A per-table count of what is about to be cascade-DESTROYED alongside
     * this Lead — every table below has a `lead_id` FK with cascadeOnDelete
     * (see the Lead Management migrations), so these rows are physically
     * gone once $lead->delete() runs, not merely detached. Deliberately
     * excludes lead_consents/lead_consent_events (nullOnDelete — they
     * survive, handled separately above) and the handful of other
     * nullOnDelete tables (lead_import_rows, failed_email_messages) whose
     * rows are only detached, not destroyed. lead_duplicate_links is
     * included even though its loss on delete is an accepted, unrecoverable
     * side effect (no tombstone) — this count is the only record that it
     * happened.
     *
     * @return array<string, int>
     */
    private function cascadeSummary(Lead $lead): array
    {
        return [
            'attachments' => $lead->attachments()->count(),
            'notes' => $lead->leadNotes()->count(),
            'assignments' => $lead->assignments()->count(),
            'status_logs' => $lead->statusLogs()->count(),
            'detail_update_logs' => $lead->detailUpdateLogs()->count(),
            'score_logs' => LeadScoreLog::where('lead_id', $lead->id)->count(),
            'tasks' => $lead->tasks()->count(),
            'deals' => $lead->deals()->count(),
            'tags' => $lead->tags()->count(),
            'custom_field_values' => $lead->customFieldValues()->count(),
            'conversations' => $lead->conversations()->count(),
            'messages' => $lead->messages()->count(),
            'calls' => $lead->calls()->count(),
            'duplicate_links' => $lead->duplicateLinksAsLead()->count() + $lead->duplicateLinksAsDuplicate()->count(),
        ];
    }
}
