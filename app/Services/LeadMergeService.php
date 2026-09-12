<?php

namespace App\Services;

use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Enums\LeadDuplicateLinkStatus;
use App\Exceptions\LeadMergeException;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadAttachment;
use App\Models\LeadConsent;
use App\Models\LeadConsentEvent;
use App\Models\LeadCustomFieldValue;
use App\Models\LeadDetailUpdateLog;
use App\Models\LeadDuplicateLink;
use App\Models\LeadNote;
use App\Models\LeadStatusLog;
use App\Models\Task;
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
     * Deal::class and Task::class both belong here (plain lead_id-keyed
     * children, exactly like the others) — DealStatusLog::class/
     * TaskStatusLog::class deliberately do NOT: both are keyed by their own
     * parent's id (deal_id/task_id), not lead_id, so running either through
     * this generic "where lead_id = loser update lead_id = survivor"
     * statement would be wrong (neither column exists on those tables). A
     * Deal's or Task's own id never changes here — only its lead_id column
     * is updated — so every status-log row stays correctly attached to its
     * parent automatically, with no reparenting statement of its own
     * required. Do not add DealStatusLog::class or TaskStatusLog::class to
     * this array. A Task's own deal_id (if set) also stays valid for free:
     * both it and the Deal it points at get reparented to the same
     * survivor by this same loop.
     *
     * LeadConsentEvent::class (Feature #14) belongs here too — it's the
     * permanent, append-only history table, a plain lead_id-keyed child
     * exactly like the others, with no unique constraint to collide with.
     * LeadConsent::class (the CURRENT-state table) deliberately does NOT —
     * its unique(lead_id, consent_type) constraint means a blind reparent
     * could collide if both leads already have their own row for the same
     * type, so it gets its own bespoke handling instead (see
     * mergeConsents()), the same reasoning LeadCustomFieldValue/LeadTag's
     * pivot already established for their own unique-constrained data.
     *
     * @var array<int, class-string>
     */
    private const array CHILD_MODELS = [
        LeadNote::class,
        LeadAttachment::class,
        LeadStatusLog::class,
        LeadAssignment::class,
        LeadDetailUpdateLog::class,
        Task::class,
        Deal::class,
        LeadConsentEvent::class,
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
     * Feature #11's parallel to conflictingFields() above, for custom field
     * values — every custom field definition where survivor and loser BOTH
     * carry a non-empty, differing value. Namespaced ("custom_field:{id}")
     * so a caller can merge this into the same $conflicts/$fieldResolutions
     * array conflictingFields() already populates without ever colliding
     * with a scalar Lead column name.
     *
     * Deliberately excludes the case where the survivor has no value and
     * the loser does — that's auto-filled directly by
     * autoFillCustomFieldValues() below, never surfaced as a conflict
     * requiring explicit resolution. This is an intentional, approved
     * divergence from conflictingFields()'s own default (which flags a
     * survivor-empty/loser-has-value pair as a conflict that defaults to
     * "keep survivor empty") — scoped entirely to custom fields, and does
     * not change conflictingFields()'s existing scalar-field behavior at
     * all.
     *
     * Considers a lead's custom field values regardless of whether their
     * defining LeadCustomFieldDefinition is currently active — a merge
     * consolidates a lead's actual stored data, and a deactivated field's
     * already-captured value is still real data deserving the same
     * treatment (it stays visible read-only elsewhere in the UI too, see
     * LeadCustomFieldDefinition's docblock).
     *
     * @return array<string, array{survivor: mixed, loser: mixed}>
     */
    public function conflictingCustomFieldValues(Lead $survivor, Lead $loser): array
    {
        $survivorValues = LeadCustomFieldValue::where('lead_id', $survivor->id)->pluck('value', 'lead_custom_field_definition_id');
        $loserValues = LeadCustomFieldValue::where('lead_id', $loser->id)->pluck('value', 'lead_custom_field_definition_id');

        $conflicts = [];

        foreach ($loserValues as $definitionId => $loserValue) {
            if ($loserValue === null || $loserValue === '') {
                continue;
            }

            $survivorValue = $survivorValues->get($definitionId);

            if ($survivorValue === null || $survivorValue === '') {
                // No conflict to resolve — autoFillCustomFieldValues() copies
                // the loser's value directly.
                continue;
            }

            if ($survivorValue === $loserValue) {
                continue;
            }

            $conflicts['custom_field:'.$definitionId] = ['survivor' => $survivorValue, 'loser' => $loserValue];
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
        $customFieldConflicts = $this->conflictingCustomFieldValues($survivor, $loser);

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

        // Same snapshot shape as the scalar loop above, kept in the SAME
        // $resolutionSnapshot array under the "custom_field:{id}" namespace
        // — one unified audit trail on LeadDuplicateLink.field_resolutions,
        // not a second column. Applying the chosen 'loser' value itself
        // happens inside the transaction below
        // (applyCustomFieldConflictResolutions()), not here — unlike the
        // scalar $updates array, a custom field's resolved value is written
        // to a separate table, not filled onto $survivor directly.
        foreach ($customFieldConflicts as $field => $values) {
            $choice = $fieldResolutions[$field] ?? 'survivor';

            $resolutionSnapshot[$field] = [
                'chosen' => $choice,
                'survivor_value' => $values['survivor'],
                'loser_value' => $values['loser'],
            ];
        }

        return DB::transaction(function () use ($survivor, $loser, $performedBy, $updates, $customFieldConflicts, $fieldResolutions, $resolutionSnapshot, $link): Lead {
            foreach (self::CHILD_MODELS as $modelClass) {
                $modelClass::query()->where('lead_id', $loser->id)->update(['lead_id' => $survivor->id]);
            }

            $this->unionTags($survivor, $loser);
            $this->mergeConsents($survivor, $loser, $performedBy);

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

            $this->autoFillCustomFieldValues($survivor, $loser);
            $this->applyCustomFieldConflictResolutions($survivor, $customFieldConflicts, $fieldResolutions);

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

    /**
     * Unions the loser's tags onto the survivor — every tag either lead had
     * ends up on the survivor exactly once, never duplicated. Tags aren't
     * in MERGEABLE_FIELDS (they're a many-to-many relation, not a scalar
     * Lead column) and aren't in CHILD_MODELS (reparenting a pivot row
     * makes no sense — a tag definition itself isn't owned by either lead,
     * only the attachment is). Runs inside the caller's existing
     * DB::transaction() — no nested transaction, plain statements added to
     * the same one. Attaches new rows individually (rather than a single
     * syncWithoutDetaching($ids) call) so a tag carried over from the loser
     * keeps its original tagged_by attribution instead of losing it. Reads
     * the loser's pivot rows via a plain query builder select on
     * lead_lead_tag (rather than $loser->tags's Eloquent pivot magic
     * property) so every value here is a plain, statically-typed stdClass
     * property access.
     */
    private function unionTags(Lead $survivor, Lead $loser): void
    {
        $survivorTagIds = $survivor->tags()->pluck('lead_tags.id')->all();

        $loserPivotRows = DB::table('lead_lead_tag')->where('lead_id', $loser->id)->get(['lead_tag_id', 'tagged_by']);

        foreach ($loserPivotRows as $row) {
            if (in_array($row->lead_tag_id, $survivorTagIds, true)) {
                continue;
            }

            $survivor->tags()->attach($row->lead_tag_id, ['tagged_by' => $row->tagged_by]);
            $survivorTagIds[] = $row->lead_tag_id;
        }
    }

    /**
     * Feature #11 — every custom field where the survivor has no value and
     * the loser does: copied directly onto the survivor, never surfaced as
     * a merge conflict (see conflictingCustomFieldValues()'s docblock for
     * why this is an approved, custom-field-only divergence from
     * MERGEABLE_FIELDS' own default). Runs inside the caller's existing
     * DB::transaction() — no nested transaction, plain statements added to
     * the same one, same pattern as unionTags() above.
     *
     * The loser's OWN value row for every field handled here (and for every
     * field the survivor already had its own value for) is deliberately
     * left exactly where it is — custom field values are in neither
     * MERGEABLE_FIELDS nor CHILD_MODELS (reparenting by lead_id would
     * collide with the (lead_id, definition_id) unique constraint whenever
     * the survivor already owns a row for the same definition), so the
     * loser keeps its own full value history, fully queryable, exactly like
     * an untouched scalar field's loser value already does. The loser is
     * never hard-deleted (see the class docblock), so nothing here is ever
     * actually lost.
     */
    private function autoFillCustomFieldValues(Lead $survivor, Lead $loser): void
    {
        $survivorDefinitionIds = LeadCustomFieldValue::where('lead_id', $survivor->id)
            ->pluck('lead_custom_field_definition_id')
            ->all();

        $loserValues = LeadCustomFieldValue::where('lead_id', $loser->id)
            ->whereNotIn('lead_custom_field_definition_id', $survivorDefinitionIds)
            ->get(['lead_custom_field_definition_id', 'value']);

        foreach ($loserValues as $loserValue) {
            if ($loserValue->value === null || $loserValue->value === '') {
                continue;
            }

            LeadCustomFieldValue::create([
                'tenant_id' => $survivor->tenant_id,
                'lead_id' => $survivor->id,
                'lead_custom_field_definition_id' => $loserValue->lead_custom_field_definition_id,
                'value' => $loserValue->value,
            ]);
        }
    }

    /**
     * Feature #11 — applies the admin's explicit choice for every
     * "custom_field:{id}"-namespaced entry in $conflicts, defaulting to
     * 'survivor' (a no-op — the survivor's own value is already correct)
     * exactly like the scalar-field loop in merge() does. Only a
     * 'loser' choice ever changes anything, and only for fields
     * conflictingCustomFieldValues() actually flagged.
     *
     * @param  array<string, array{survivor: mixed, loser: mixed}>  $conflicts
     * @param  array<string, string>  $fieldResolutions
     */
    private function applyCustomFieldConflictResolutions(Lead $survivor, array $conflicts, array $fieldResolutions): void
    {
        foreach ($conflicts as $key => $values) {
            $choice = $fieldResolutions[$key] ?? 'survivor';

            if ($choice !== 'loser') {
                continue;
            }

            $definitionId = substr($key, strlen('custom_field:'));

            LeadCustomFieldValue::updateOrCreate(
                ['lead_id' => $survivor->id, 'lead_custom_field_definition_id' => $definitionId],
                ['tenant_id' => $survivor->tenant_id, 'value' => $values['loser']],
            );
        }
    }

    /**
     * Feature #14 — reconciles CURRENT-state consent for every
     * App\Enums\ConsentType. Runs inside the caller's existing
     * DB::transaction() — no nested transaction, plain statements added to
     * the same one, same pattern as unionTags()/autoFillCustomFieldValues()
     * above. LeadConsentEvent (the append-only history) is already handled
     * generically by the CHILD_MODELS loop above this call — both leads'
     * full histories are preserved on the survivor regardless of what
     * happens here.
     *
     * Conservative, deterministic "most restrictive wins" rule — with only
     * two possible statuses, this fully resolves every combination with no
     * admin conflict-resolution step needed, unlike a scalar field's
     * multi-valued conflicts:
     *   - either side Withdrawn => survivor ends Withdrawn (a withdrawal is
     *     never silently downgraded back to Granted by a merge)
     *   - both Granted => survivor stays/becomes Granted
     *   - only one side has a record => that record's status is copied onto
     *     the survivor (auto-fill, never a "conflict" — same spirit as
     *     autoFillCustomFieldValues())
     *   - neither side has a record => remains Not Provided, nothing written
     *
     * A synthetic LeadConsentEvent (source = Merge) is written for every
     * type where at least one side had a record, even when the survivor's
     * own state doesn't actually change — full traceability that a merge
     * happened and what it resolved to, not just a silent no-op.
     */
    private function mergeConsents(Lead $survivor, Lead $loser, TenantUser $performedBy): void
    {
        $survivorConsents = LeadConsent::where('lead_id', $survivor->id)->get()->keyBy(fn (LeadConsent $c): string => $c->consent_type->value);
        $loserConsents = LeadConsent::where('lead_id', $loser->id)->get()->keyBy(fn (LeadConsent $c): string => $c->consent_type->value);

        foreach (ConsentType::cases() as $type) {
            $survivorConsent = $survivorConsents->get($type->value);
            $loserConsent = $loserConsents->get($type->value);

            if ($survivorConsent === null && $loserConsent === null) {
                continue;
            }

            $resultStatus = ($survivorConsent?->status === ConsentStatus::Withdrawn || $loserConsent?->status === ConsentStatus::Withdrawn)
                ? ConsentStatus::Withdrawn
                : ConsentStatus::Granted;

            $attributes = [
                'tenant_id' => $survivor->tenant_id,
                'lead_id' => $survivor->id,
                'consent_type' => $type,
                'status' => $resultStatus,
                'source' => ConsentSource::Merge,
                'website_form_id' => null,
                'website_form_name' => null,
                'changed_by' => $performedBy->id,
                'policy_version' => null,
                'policy_url' => null,
                'ip_address' => null,
                'user_agent' => null,
                'occurred_at' => now(),
            ];

            if ($survivorConsent === null || $survivorConsent->status !== $resultStatus) {
                LeadConsent::updateOrCreate(
                    ['lead_id' => $survivor->id, 'consent_type' => $type],
                    $attributes,
                );
            }

            LeadConsentEvent::create($attributes);
        }
    }
}
