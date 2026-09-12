<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadCustomFieldValue;
use App\Models\LeadDetailUpdateLog;
use Livewire\Livewire;

test('a client admin can set a custom field value on an existing lead from the detail page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source', 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('startEditingCustomFields')
        ->set('customFieldValues.'.$definition->id, 'Referral')
        ->call('updateCustomFields')
        ->assertHasNoErrors();

    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->first()?->value)
        ->toBe('Referral');

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->firstOrFail();
    expect($log->changed_fields)->toBe(['Referral Source']);
});

test('editing custom fields without changing any value writes no new LeadDetailUpdateLog entry', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
        'value' => 'Unchanged',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('startEditingCustomFields')
        ->set('customFieldValues.'.$definition->id, 'Unchanged')
        ->call('updateCustomFields')
        ->assertHasNoErrors();

    expect(LeadDetailUpdateLog::where('lead_id', $lead->id)->count())->toBe(0);
});

test('changing only one of several custom fields logs exactly that one field', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $unchangedDefinition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Unchanged Field', 'type' => 'text']);
    $changedDefinition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Changed Field', 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $unchangedDefinition->id, 'value' => 'stays',
    ]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id, 'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $changedDefinition->id, 'value' => 'old',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('startEditingCustomFields')
        ->set('customFieldValues.'.$unchangedDefinition->id, 'stays')
        ->set('customFieldValues.'.$changedDefinition->id, 'new')
        ->call('updateCustomFields')
        ->assertHasNoErrors();

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->firstOrFail();
    expect($log->changed_fields)->toBe(['Changed Field']);
});

test('clearing a custom field value on edit deletes its value row rather than storing an empty string', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source', 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
        'value' => 'Referral',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('startEditingCustomFields')
        ->set('customFieldValues.'.$definition->id, '')
        ->call('updateCustomFields')
        ->assertHasNoErrors();

    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->exists())->toBeFalse();

    $log = LeadDetailUpdateLog::where('lead_id', $lead->id)->firstOrFail();
    expect($log->changed_fields)->toBe(['Referral Source']);
});

test('a required custom field cannot be cleared to blank on edit', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->required()->create(['tenant_id' => $tenant->id, 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
        'value' => 'Has a value',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('startEditingCustomFields')
        ->set('customFieldValues.'.$definition->id, '')
        ->call('updateCustomFields')
        ->assertHasErrors('customFieldValues.'.$definition->id);

    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->first()?->value)
        ->toBe('Has a value');
});

test('a lead created before a field was marked required stays valid and editable without filling it in', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Contract Type', 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Name', 'phone' => '+8801712345678']);

    // The field is marked required AFTER this lead already exists with no value.
    $definition->update(['is_required' => true]);

    // Editing an unrelated field must not be blocked by the now-required,
    // still-blank custom field — not retroactive.
    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadName', 'New Name')
        ->call('updateContact')
        ->assertHasNoErrors();

    expect($lead->fresh()->name)->toBe('New Name');
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->exists())->toBeFalse();
});

test('a custom field id belonging to another tenant is never written when editing a lead', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $foreignDefinition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenantB->id, 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenantA->id]);

    Livewire::actingAs($adminA)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('startEditingCustomFields')
        ->set('customFieldValues.'.$foreignDefinition->id, 'sneaky value')
        ->call('updateCustomFields')
        ->assertHasNoErrors();

    expect(LeadCustomFieldValue::where('lead_custom_field_definition_id', $foreignDefinition->id)->exists())->toBeFalse();
});

test('a client user assigned to the lead can edit its custom field values', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('startEditingCustomFields')
        ->set('customFieldValues.'.$definition->id, 'Set by rep')
        ->call('updateCustomFields')
        ->assertHasNoErrors();

    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->first()?->value)
        ->toBe('Set by rep');
});

test('a sales rep without update permission cannot edit custom field values', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('customFieldValues.'.$definition->id, 'should not save')
        ->call('updateCustomFields')
        ->assertDispatched('toast-show');

    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->exists())->toBeFalse();
});
