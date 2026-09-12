<?php

use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadCustomFieldValue;
use App\Models\TenantUser;
use Livewire\Livewire;

function createLeadWithCustomFieldValues(TenantUser $clientAdmin, array $customFieldValues): void
{
    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Rafiqul Islam')
        ->set('leadPhone', '+8801712345678')
        ->set('customFieldValues', $customFieldValues)
        ->call('saveLead')
        ->assertHasNoErrors();
}

test('a text custom field value is saved on manual lead creation', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source', 'type' => 'text']);

    createLeadWithCustomFieldValues($clientAdmin, [$definition->id => 'Word of mouth']);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->first()?->value)
        ->toBe('Word of mouth');
});

test('a number custom field rejects a non-numeric value', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'type' => 'number']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Rafiqul Islam')
        ->set('leadPhone', '+8801712345678')
        ->set('customFieldValues', [$definition->id => 'not-a-number'])
        ->call('saveLead')
        ->assertHasErrors('customFieldValues.'.$definition->id);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('a number custom field accepts a numeric value', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'type' => 'number']);

    createLeadWithCustomFieldValues($clientAdmin, [$definition->id => '1500.50']);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->first()?->value)
        ->toBe('1500.50');
});

test('a date custom field rejects an invalid date', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'type' => 'date']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Rafiqul Islam')
        ->set('leadPhone', '+8801712345678')
        ->set('customFieldValues', [$definition->id => 'not-a-date'])
        ->call('saveLead')
        ->assertHasErrors('customFieldValues.'.$definition->id);
});

test('a select custom field rejects a value outside its option list', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'type' => 'select', 'options' => ['Annual', 'Monthly']]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Rafiqul Islam')
        ->set('leadPhone', '+8801712345678')
        ->set('customFieldValues', [$definition->id => 'Weekly'])
        ->call('saveLead')
        ->assertHasErrors('customFieldValues.'.$definition->id);
});

test('a select custom field accepts a value from its option list', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'type' => 'select', 'options' => ['Annual', 'Monthly']]);

    createLeadWithCustomFieldValues($clientAdmin, [$definition->id => 'Annual']);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->first()?->value)
        ->toBe('Annual');
});

test('a required custom field blocks lead creation when left blank', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->required()->create(['tenant_id' => $tenant->id, 'type' => 'text']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead')
        ->set('leadName', 'Rafiqul Islam')
        ->set('leadPhone', '+8801712345678')
        ->call('saveLead')
        ->assertHasErrors('customFieldValues.'.$definition->id);

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('an optional custom field left blank does not block lead creation and creates no value row', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'type' => 'text']);

    createLeadWithCustomFieldValues($clientAdmin, []);

    $lead = Lead::where('tenant_id', $tenant->id)->firstOrFail();
    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->exists())->toBeFalse();
});

test('an inactive custom field is neither validated nor rendered on the New Lead form', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadCustomFieldDefinition::factory()->inactive()->required()->create(['tenant_id' => $tenant->id, 'name' => 'Retired Field', 'type' => 'text']);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->call('createLead');

    $component->assertDontSee('Retired Field');

    $component->set('leadName', 'Rafiqul Islam')
        ->set('leadPhone', '+8801712345678')
        ->call('saveLead')
        ->assertHasNoErrors();

    expect(Lead::where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

test('a custom field id belonging to another tenant is never written', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();
    $foreignDefinition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenantB->id, 'type' => 'text']);

    createLeadWithCustomFieldValues($adminA, [$foreignDefinition->id => 'sneaky value']);

    expect(LeadCustomFieldValue::where('lead_custom_field_definition_id', $foreignDefinition->id)->exists())->toBeFalse();
});
