<?php

use App\Exceptions\LeadMergeException;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadCustomFieldValue;
use App\Models\LeadDuplicateLink;
use App\Services\LeadDuplicateDetectionService;
use App\Services\LeadMergeService;
use Livewire\Livewire;

test('merging auto-fills a custom field the survivor has no value for from the loser', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $loser->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'From loser',
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(LeadCustomFieldValue::where('lead_id', $survivor->id)->where('lead_custom_field_definition_id', $definition->id)->first()?->value)
        ->toBe('From loser');
});

test('merging two leads with no custom field values on either side does nothing', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(LeadCustomFieldValue::where('lead_id', $survivor->id)->exists())->toBeFalse();
});

test('merging two leads with the same custom field value on both sides is not a conflict', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $survivor->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Same',
    ]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $loser->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Same',
    ]);

    $conflicts = app(LeadMergeService::class)->conflictingCustomFieldValues($survivor, $loser);
    expect($conflicts)->toBe([]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);
    expect(LeadCustomFieldValue::where('lead_id', $survivor->id)->first()->value)->toBe('Same');
});

test('different custom field values on both sides are flagged as an explicit conflict', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $survivor->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Survivor value',
    ]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $loser->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Loser value',
    ]);

    $conflicts = app(LeadMergeService::class)->conflictingCustomFieldValues($survivor, $loser);

    expect($conflicts)->toHaveKey('custom_field:'.$definition->id);
    expect($conflicts['custom_field:'.$definition->id])->toBe(['survivor' => 'Survivor value', 'loser' => 'Loser value']);
});

test('an unresolved custom field conflict defaults to keeping the survivor\'s value', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $survivor->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Survivor value',
    ]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $loser->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Loser value',
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    expect(LeadCustomFieldValue::where('lead_id', $survivor->id)->where('lead_custom_field_definition_id', $definition->id)->first()->value)
        ->toBe('Survivor value');
});

test('explicitly choosing the loser\'s value for a conflicting custom field applies it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $survivor->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Survivor value',
    ]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $loser->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Loser value',
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, [
        'custom_field:'.$definition->id => 'loser',
    ]);

    expect(LeadCustomFieldValue::where('lead_id', $survivor->id)->where('lead_custom_field_definition_id', $definition->id)->first()->value)
        ->toBe('Loser value');
});

test('a resolved custom field conflict is recorded in the field_resolutions snapshot', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $survivor->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Survivor value',
    ]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $loser->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Loser value',
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, [
        'custom_field:'.$definition->id => 'loser',
    ]);

    $link = LeadDuplicateLink::where('tenant_id', $tenant->id)->firstOrFail();
    expect($link->field_resolutions['custom_field:'.$definition->id])->toBe([
        'chosen' => 'loser',
        'survivor_value' => 'Survivor value',
        'loser_value' => 'Loser value',
    ]);
});

test('the loser\'s own custom field values remain queryable on the retired lead after merge', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $autoFilledDefinition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $conflictDefinition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);

    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $loser->id,
        'lead_custom_field_definition_id' => $autoFilledDefinition->id, 'value' => 'Auto-filled',
    ]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $survivor->id,
        'lead_custom_field_definition_id' => $conflictDefinition->id, 'value' => 'Kept survivor',
    ]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $loser->id,
        'lead_custom_field_definition_id' => $conflictDefinition->id, 'value' => 'Not chosen',
    ]);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    // Both of the loser's own rows survive untouched, still attached to the
    // now-merged-away (never hard-deleted) loser lead.
    expect(LeadCustomFieldValue::where('lead_id', $loser->id)->where('lead_custom_field_definition_id', $autoFilledDefinition->id)->first()?->value)
        ->toBe('Auto-filled');
    expect(LeadCustomFieldValue::where('lead_id', $loser->id)->where('lead_custom_field_definition_id', $conflictDefinition->id)->first()?->value)
        ->toBe('Not chosen');
    expect($loser->fresh()->merged_into_lead_id)->toBe($survivor->id);
});

test('custom field merge logic never bypasses the tenant-mismatch guard', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenantA->id]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenantA->id]);
    $loser = Lead::factory()->create(['tenant_id' => $tenantB->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenantB->id, 'lead_id' => $loser->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'x',
    ]);

    expect(fn () => app(LeadMergeService::class)->merge($survivor, $loser, $adminA, []))
        ->toThrow(LeadMergeException::class);

    expect(LeadCustomFieldValue::where('lead_id', $survivor->id)->exists())->toBeFalse();
});

test('a client admin can resolve a custom field conflict from the lead detail merge modal', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source']);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dup@example.com']);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $survivor->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Survivor value',
    ]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $loser->id,
        'lead_custom_field_definition_id' => $definition->id, 'value' => 'Loser value',
    ]);

    $link = app(LeadDuplicateDetectionService::class)->detectFor($survivor)->first();

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $survivor])
        ->call('completeInitialLoad')
        ->call('openMergeModal', $link->id)
        ->assertSet('showMergeModal', true)
        ->assertSee('Referral Source')
        ->assertSee('Survivor value')
        ->assertSee('Loser value')
        ->set('mergeFieldResolutions.custom_field:'.$definition->id, 'loser')
        ->call('confirmMerge')
        ->assertDispatched('toast-show');

    expect(LeadCustomFieldValue::where('lead_id', $survivor->id)->where('lead_custom_field_definition_id', $definition->id)->first()->value)
        ->toBe('Loser value');
});
