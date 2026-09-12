<?php

use App\Livewire\Tenant\LeadCustomFields\Index;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadCustomFieldValue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('a client admin can create a text custom field', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Referral Source')
        ->set('type', 'text')
        ->set('sortOrder', '5')
        ->call('save')
        ->assertHasNoErrors();

    $definition = LeadCustomFieldDefinition::where('tenant_id', $tenant->id)->firstOrFail();
    expect($definition->name)->toBe('Referral Source');
    expect($definition->key)->toBe('referral-source');
    expect($definition->type->value)->toBe('text');
    expect($definition->sort_order)->toBe(5);
    expect($definition->is_active)->toBeTrue();
    expect($definition->is_required)->toBeFalse();
});

test('a client admin can create a required select field with options', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Contract Type')
        ->set('type', 'select')
        ->set('optionsText', "Annual\nMonthly\n\nMonthly")
        ->set('isRequired', true)
        ->call('save')
        ->assertHasNoErrors();

    $definition = LeadCustomFieldDefinition::where('tenant_id', $tenant->id)->firstOrFail();
    expect($definition->type->value)->toBe('select');
    expect($definition->options)->toBe(['Annual', 'Monthly']);
    expect($definition->is_required)->toBeTrue();
});

test('a select field requires at least one option', function () {
    ['clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Contract Type')
        ->set('type', 'select')
        ->set('optionsText', "  \n ")
        ->call('save')
        ->assertHasErrors('optionsText');
});

test('a client admin can edit a custom field\'s name, required flag, sort order and active state', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Original']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $definition->id)
        ->set('name', 'Renamed')
        ->set('isRequired', true)
        ->set('isActive', false)
        ->set('sortOrder', '9')
        ->call('save')
        ->assertHasNoErrors();

    $definition->refresh();
    expect($definition->name)->toBe('Renamed');
    expect($definition->key)->toBe('renamed');
    expect($definition->is_required)->toBeTrue();
    expect($definition->is_active)->toBeFalse();
    expect($definition->sort_order)->toBe(9);
});

test('a custom field name must be unique within a tenant', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source', 'key' => 'referral-source']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Referral Source')
        ->call('save')
        ->assertHasErrors('name');

    expect(LeadCustomFieldDefinition::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a custom field name colliding only after slugification is still rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source', 'key' => 'referral-source']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'referral source')
        ->call('save')
        ->assertHasErrors('name');

    expect(LeadCustomFieldDefinition::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('the same custom field name/key is allowed independently across different tenants', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB, 'clientAdmin' => $adminB] = tenantWithLeadManagement();

    Livewire::actingAs($adminA)->test(Index::class)->call('create')->set('name', 'Referral Source')->call('save')->assertHasNoErrors();
    Livewire::actingAs($adminB)->test(Index::class)->call('create')->set('name', 'Referral Source')->call('save')->assertHasNoErrors();

    expect(LeadCustomFieldDefinition::where('tenant_id', $tenantA->id)->where('key', 'referral-source')->exists())->toBeTrue();
    expect(LeadCustomFieldDefinition::where('tenant_id', $tenantB->id)->where('key', 'referral-source')->exists())->toBeTrue();
});

test('deactivating and reactivating a custom field toggles is_active without touching its values', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
        'value' => 'kept',
    ]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);

    $component->call('toggleActive', $definition->id);
    expect($definition->fresh()->is_active)->toBeFalse();

    $component->call('toggleActive', $definition->id);
    expect($definition->fresh()->is_active)->toBeTrue();

    expect(LeadCustomFieldValue::where('lead_id', $lead->id)->where('lead_custom_field_definition_id', $definition->id)->first()?->value)->toBe('kept');
});

test('a client admin can delete an unused custom field', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $definition->id)
        ->assertSet('confirmingDeleteUsageCount', 0)
        ->call('deleteConfirmed');

    expect(LeadCustomFieldDefinition::find($definition->id))->toBeNull();
});

test('deleting a custom field is blocked while any lead has a value for it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $definition->id)
        ->assertSet('confirmingDeleteUsageCount', 1)
        ->call('deleteConfirmed');

    expect(LeadCustomFieldDefinition::find($definition->id))->not->toBeNull();
});

test('type and options cannot be changed once a custom field has a value on any lead', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budget Tier', 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
        'value' => 'gold',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $definition->id)
        ->assertSet('editingHasValues', true)
        ->set('type', 'number')
        ->call('save')
        ->assertHasErrors('type');

    expect($definition->fresh()->type->value)->toBe('text');
});

test('editing a custom field with no values may freely change its type', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'type' => 'text']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $definition->id)
        ->assertSet('editingHasValues', false)
        ->set('type', 'number')
        ->call('save')
        ->assertHasNoErrors();

    expect($definition->fresh()->type->value)->toBe('number');
});

test('the catalog shows how many leads currently use each custom field', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source']);
    $leads = Lead::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    foreach ($leads as $lead) {
        LeadCustomFieldValue::factory()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'lead_custom_field_definition_id' => $definition->id,
        ]);
    }

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertSee('3 leads');
});

test('a client user without admin rights cannot access the custom field catalog page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertForbidden();
});

test('a tenant\'s custom field catalog never appears for, or can be touched by, another tenant', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();

    $definitionA = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A Field']);
    $definitionB = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B Field']);

    $component = Livewire::actingAs($adminA)->test(Index::class);

    $component->assertSee('Tenant A Field')->assertDontSee('Tenant B Field');

    expect(fn () => $component->call('edit', $definitionB->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('confirmDelete', $definitionB->id)->call('deleteConfirmed'))->toThrow(ModelNotFoundException::class);

    expect($definitionA->fresh())->not->toBeNull();
    expect(LeadCustomFieldDefinition::where('tenant_id', $tenantB->id)->count())->toBe(1);
});
