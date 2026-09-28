<?php

namespace App\Actions\Tenant;

use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadCustomFieldValue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single "validate-against-current-active-definitions, diff, upsert-or-
 * delete" primitive shared by manual Lead creation (Index::saveLead()) and
 * the Lead detail edit form (Show::applyCustomFieldChanges()) — Feature #11.
 *
 * Deliberately re-derives this tenant's own ACTIVE definitions from the
 * database rather than trusting the caller's submitted keys: a definition id
 * that doesn't belong to $tenantId, or that isn't currently active, is
 * silently ignored, never written — same "never trust the dropdown alone"
 * discipline as Show::attachTag()/Task.deal_id validation elsewhere in this
 * module. Field-level type/required validation itself already happened in
 * the caller's own $this->validate([...]) call (built from each active
 * definition's own validationRules()) before this ever runs — this class
 * only persists already-validated values.
 */
class SaveLeadCustomFieldValues
{
    /**
     * A blank submitted value deletes any existing row for that field
     * rather than storing an empty string (see LeadCustomFieldValue's
     * docblock) — "no row" and "blank" are the same state, never two.
     * Returns the definition NAMES that actually changed (created, updated
     * to a different value, or removed) — used by the caller to write a
     * single LeadDetailUpdateLog entry, or none at all on a true no-op.
     *
     * @param  array<string, mixed>  $submittedValues  keyed by lead_custom_field_definition_id
     * @return array<int, string>
     */
    public function handle(Lead $lead, string $tenantId, array $submittedValues): array
    {
        return DB::transaction(function () use ($lead, $tenantId, $submittedValues): array {
            $definitions = LeadCustomFieldDefinition::query()
                ->where('tenant_id', $tenantId)
                ->active()
                ->get();

            $changedFieldNames = [];

            foreach ($definitions as $definition) {
                if (! array_key_exists($definition->id, $submittedValues)) {
                    continue;
                }

                $changed = $this->applyOne($lead, $definition, $submittedValues[$definition->id]);

                if ($changed) {
                    $changedFieldNames[] = $definition->name;
                }
            }

            return $changedFieldNames;
        });
    }

    private function applyOne(Lead $lead, LeadCustomFieldDefinition $definition, mixed $raw): bool
    {
        $value = is_string($raw) ? trim($raw) : $raw;
        $blank = $value === null || $value === '';

        $existing = LeadCustomFieldValue::query()
            ->where('lead_id', $lead->id)
            ->where('lead_custom_field_definition_id', $definition->id)
            ->first();

        if ($blank) {
            if ($existing === null) {
                return false;
            }

            $existing->delete();

            return true;
        }

        $stringValue = (string) $value;

        if ($existing !== null) {
            if ($existing->value === $stringValue) {
                return false;
            }

            $existing->update(['value' => $stringValue]);

            return true;
        }

        // Race-safe create: two concurrent submissions for this same
        // lead+definition can both observe $existing === null above and
        // both attempt create() — a plain create() has no recovery for
        // that, and the loser's INSERT hits the real
        // unique(lead_id, lead_custom_field_definition_id) constraint
        // uncaught, aborting this whole submission's DB::transaction() (see
        // handle()) and rolling back every OTHER field in the same
        // submission along with it.
        try {
            // withSavepointIfNeeded() equivalent: handle() already runs
            // this inside its own DB::transaction() (transactionLevel() >
            // 0), so a nested DB::transaction() here is a SAVEPOINT — on
            // Postgres, an uncaught constraint violation would otherwise
            // leave the OUTER transaction aborted even after this catch,
            // failing the recovery re-read below and every other field's
            // own write in the same submission.
            DB::transaction(fn () => LeadCustomFieldValue::create([
                'tenant_id' => $definition->tenant_id,
                'lead_id' => $lead->id,
                'lead_custom_field_definition_id' => $definition->id,
                'value' => $stringValue,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Lost the race: a concurrent submission created the row first,
            // between our own read above and this create(). Re-read it and
            // apply this call's own value through the same update path the
            // pre-existing-row branch above already uses — "changed" only
            // if the value actually differs.
            $existing = LeadCustomFieldValue::query()
                ->where('lead_id', $lead->id)
                ->where('lead_custom_field_definition_id', $definition->id)
                ->firstOrFail();

            if ($existing->value === $stringValue) {
                return false;
            }

            $existing->update(['value' => $stringValue]);
        }

        return true;
    }
}
