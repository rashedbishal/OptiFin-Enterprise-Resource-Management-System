<?php

namespace App\Services;

use App\Enums\LeadDuplicateLinkStatus;
use App\Exceptions\LeadMergeException;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadAttachment;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadDuplicateLink;
use App\Models\LeadNote;
use App\Models\LeadStatusLog;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;

/**
 * Merges two same-tenant leads that App\Services\LeadDuplicateDetectionService
 * flagged as possible duplicates. Deliberately does not delete the "loser"
 * lead — its identifier is never reclaimed (see .ai/rules/factories.md) and
 * every one of its child records (notes, attachments, status history,
 * assignment history, detail-update history) stays fully queryable, just
 * reparented onto the surviving lead. The loser is marked merged_into_lead_id
 * and excluded from normal list views (Index::filteredQuery(),
 * SourceLeads::baseQuery()) but never hard-deleted.
 */
class LeadMergeService
{
    public function __construct(private readonly LeadScoringService $scoringService) {}

    /**
     * Lead columns a merge can move from the loser onto the survivor — only
     * scalar contact/company/deal fields a rep would actually want resolved
     * one way or the other. Deliberately excludes pipeline/relationship
     * columns (status_id, assigned_to, source_id, priority, notes, message,
     * source_data, dates): those aren't "which contact's info is right"
     * conflicts, they're the survivor's own ongoing pipeline state, which a
     * merge must never silently overwrite.
     *
     * @var array<int, string>
     */
    private const array MERGEABLE_FIELDS = [
        'name', 'email', 'phone', 'company', 'website', 'industry', 'location',
        'service_interest', 'estimated_budget', 'estimated_budget_currency_id',
        'deal_value', 'deal_value_currency_id', 'win_probability',
    ];

    /**
     * Every child table keyed by lead_id that a merge reparents from the
     * loser onto the survivor, preserving all of both leads' history.
     *
     * @var array<int, class-string>
     */
    private const array CHILD_MODELS = [
        LeadNote::class,
        LeadAttachment::class,
        LeadStatusLog::class,
        LeadAssignment::class,
        LeadDetailUpdateLog::class,
    ];

    /**
     * Every MERGEABLE_FIELDS column where the loser has a non-empty value
     * that actually differs from the survivor's — the set a caller must
     * resolve (or accept the safe "keep survivor" default for) before
     * calling merge(). Fields not returned here are never touched by a
     * merge, regardless of what's passed in $fieldResolutions.
     *
     * @return array<string, array{survivor: mixed, loser: mixed}>
     */
    public function conflictingFields(Lead $survivor, Lead $loser): array
    {
        $conflicts = [];

        foreach (self::MERGEABLE_FIELDS as $field) {
            $survivorValue = $survivor->{$field};
            $loserValue = $loser->{$field};

            if ($loserValue === null || $loserValue === '') {
                continue;
            }

            if ((string) $loserValue === (string) $survivorValue) {
                continue;
            }

            $conflicts[$field] = ['survivor' => $survivorValue, 'loser' => $loserValue];
        }

        return $conflicts;
    }

    /**
     * Merges $loser into $survivor inside a single DB transaction — either
     * every step below succeeds together, or none of them are persisted at
     * all, so a failure partway through can never leave a lead half-merged.
     *
     * $fieldResolutions maps a subset of conflictingFields()'s keys to
     * 'survivor' or 'loser'; any conflicting field left unresolved (or given
     * any other value) safely defaults to keeping the survivor's own value —
     * a merge can only ever pull a loser's value onto the survivor by an
     * explicit 'loser' choice, never automatically.
     *
     * Deliberately fires no event itself — a pure data operation, same
     * division of responsibility as LeadAssignmentService::unassign(). The
     * caller (App\Livewire\Tenant\Leads\Show::confirmMerge()) broadcasts
     * LeadUpdated once, the same way it already does after its own
     * applyDetailChanges()/assign() calls.
     *
     * @param  array<string, string>  $fieldResolutions
     */
    public function merge(Lead $survivor, Lead $loser, TenantUser $performedBy, array $fieldResolutions, ?LeadDuplicateLink $link = null): Lead
    {
        if ($survivor->tenant_id !== $loser->tenant_id) {
            throw new LeadMergeException('Leads must belong to the same tenant to be merged.');
        }

        if ($survivor->id === $loser->id) {
            throw new LeadMergeException('A lead cannot be merged with itself.');
        }

        if ($survivor->merged_into_lead_id !== null || $loser->merged_into_lead_id !== null) {
            throw new LeadMergeException('One of these leads has already been merged and can no longer take part in a merge.');
        }

        $conflicts = $this->conflictingFields($survivor, $loser);

        $updates = [];
        $resolutionSnapshot = [];

        foreach ($conflicts as $field => $values) {
            $choice = $fieldResolutions[$field] ?? 'survivor';

            $resolutionSnapshot[$field] = [
                'chosen' => $choice,
                'survivor_value' => $values['survivor'],
                'loser_value' => $values['loser'],
            ];

            if ($choice === 'loser') {
                $updates[$field] = $values['loser'];
            }
        }

        return DB::transaction(function () use ($survivor, $loser, $performedBy, $updates, $resolutionSnapshot, $link): Lead {
            foreach (self::CHILD_MODELS as $modelClass) {
                $modelClass::query()->where('lead_id', $loser->id)->update(['lead_id' => $survivor->id]);
            }

            if ($updates !== []) {
                $survivor->fill($updates);
                $survivor->save();

                // A merge can pull a scoring-relevant value (budget/deal/
                // contact fields) onto the survivor via explicit field
                // resolution — recompute its score if that happened. No-op,
                // additive call: LeadScoringService opens no transaction of
                // its own, so this just adds more statements to the SAME
                // transaction already wrapping this whole merge.
                if (array_intersect(array_keys($updates), LeadScoringService::SCORING_RELEVANT_DETAIL_FIELDS) !== []) {
                    $this->scoringService->computeFor($survivor, 'merge');
                }
            }

            // Raw update, not $loser->update(): mirrors Lead::markUpdateSeen()'s
            // reasoning — this must not fire Eloquent events/broadcasts for
            // the loser, which is being retired from active views, not
            // "updated" from any viewer's perspective.
            DB::table('leads')->where('id', $loser->id)->update([
                'merged_into_lead_id' => $survivor->id,
                'updated_at' => now(),
            ]);

            $link ??= LeadDuplicateLink::query()->firstOrCreate(
                array_combine(['lead_id', 'duplicate_lead_id'], LeadDuplicateLink::pairKeys($survivor, $loser)),
                ['tenant_id' => $survivor->tenant_id, 'status' => LeadDuplicateLinkStatus::Pending, 'detected_at' => now()],
            );

            $link->update([
                'status' => LeadDuplicateLinkStatus::Merged,
                'resolved_by' => $performedBy->id,
                'resolved_at' => now(),
                'field_resolutions' => $resolutionSnapshot,
            ]);

            return $survivor->fresh();
        });
    }
}
