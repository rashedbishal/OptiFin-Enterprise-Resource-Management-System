<?php

use App\Actions\Tenant\SaveLeadCustomFieldValues;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadCustomFieldValue;
use Illuminate\Support\Facades\DB;

/*
 * Batch 7 — F3. SaveLeadCustomFieldValues::applyOne() did a plain
 * SELECT-then-create() for a lead+definition with no existing value row —
 * no recovery when two concurrent submissions (manual lead creation form,
 * or the Lead detail edit form) both see no existing row and both attempt
 * create(), so the loser's INSERT hit the real
 * unique(lead_id, lead_custom_field_definition_id) constraint uncaught,
 * aborting the whole submission's transaction and rolling back every OTHER
 * field in the same submission along with it.
 *
 * Forces the real interleaving via a DB::listen() hook on the fix's own
 * existence check, landing the competing insert before the fix's own
 * create() attempt runs — see RecordLeadConsentConcurrentCreateTest's own
 * docblock for why this is done on the SELECT rather than inside a
 * `creating` event nested in the create() call itself.
 */

function raceOnFirstCustomFieldValueSelect(string $leadId, string $definitionId, string $tenantId, string $winnerValue): void
{
    $raced = false;

    DB::listen(function ($query) use (&$raced, $leadId, $definitionId, $tenantId, $winnerValue) {
        if ($raced) {
            return;
        }

        if (! str_starts_with(ltrim($query->sql), 'select') || ! str_contains($query->sql, 'lead_custom_field_values')) {
            return;
        }

        if (! in_array($leadId, $query->bindings, true) || ! in_array($definitionId, $query->bindings, true)) {
            return;
        }

        $raced = true;

        LeadCustomFieldValue::create([
            'tenant_id' => $tenantId,
            'lead_id' => $leadId,
            'lead_custom_field_definition_id' => $definitionId,
            'value' => $winnerValue,
        ]);
    });
}

test('a concurrent custom-field-value create recovers instead of throwing, applying this call\'s own value', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);

    raceOnFirstCustomFieldValueSelect($lead->id, $definition->id, $tenant->id, 'Concurrent winner');

    $changed = app(SaveLeadCustomFieldValues::class)->handle($lead, $tenant->id, [$definition->id => 'This call value']);

    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->count())->toBe(1);
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->first()->value)->toBe('This call value');
    expect($changed)->toBe([$definition->name]);
});

test('another field in the same submission is not rolled back by the race', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $definitionA = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $definitionB = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);

    raceOnFirstCustomFieldValueSelect($lead->id, $definitionA->id, $tenant->id, 'Concurrent winner');

    $changed = app(SaveLeadCustomFieldValues::class)->handle($lead, $tenant->id, [
        $definitionA->id => 'Field A value',
        $definitionB->id => 'Field B value',
    ]);

    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definitionB->id)->first()->value)->toBe('Field B value');
    expect($changed)->toContain($definitionA->name)->toContain($definitionB->name);
});

test('the normal create/update behavior remains unchanged when there is no race', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);

    $created = app(SaveLeadCustomFieldValues::class)->handle($lead, $tenant->id, [$definition->id => 'First value']);
    expect($created)->toBe([$definition->name]);

    $updated = app(SaveLeadCustomFieldValues::class)->handle($lead, $tenant->id, [$definition->id => 'Second value']);
    expect($updated)->toBe([$definition->name]);
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->count())->toBe(1);
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->first()->value)->toBe('Second value');

    $noop = app(SaveLeadCustomFieldValues::class)->handle($lead, $tenant->id, [$definition->id => 'Second value']);
    expect($noop)->toBe([]);
});
