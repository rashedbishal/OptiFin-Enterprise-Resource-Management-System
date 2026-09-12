<?php

use App\Livewire\Tenant\Leads\Index;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadCustomFieldValue;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the full Lead detail page displays a custom field\'s value', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source', 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
        'value' => 'Word of mouth',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSee('Referral Source')
        ->assertSee('Word of mouth');
});

test('the Kanban flyout displays a custom field\'s value', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source', 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
        'value' => 'Word of mouth',
    ]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead, 'panel' => true])->call('completeInitialLoad')
        ->assertSee('Referral Source')
        ->assertSee('Word of mouth');
});

test('a deactivated field\'s historical value stays visible read-only on the Lead detail page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Retired Field', 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
        'value' => 'Still here',
    ]);
    $definition->update(['is_active' => false]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    $component->assertSee('Retired Field')->assertSee('Still here');

    // Read-only: the deactivated field must not appear in the editable form.
    $component->call('startEditingCustomFields');
    expect($component->get('customFieldValues'))->not->toHaveKey($definition->id);
});

test('custom fields never appear on the Kanban card', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source', 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
        'value' => 'Word of mouth',
    ]);

    // The field's NAME legitimately appears elsewhere on this same page (the
    // New Lead modal's own custom-fields section, always present in the DOM
    // regardless of open/closed state — see Section J of the Architecture
    // Proposal) — it's this specific LEAD's stored VALUE that must never
    // leak onto the Kanban card, which is the actual thing being tested
    // here.
    Livewire::actingAs($rep)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertDontSee('Word of mouth');
});

test('custom fields never appear on the Leads table', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Referral Source', 'type' => 'text']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadCustomFieldValue::factory()->create([
        'tenant_id' => $tenant->id,
        'lead_id' => $lead->id,
        'lead_custom_field_definition_id' => $definition->id,
        'value' => 'Word of mouth',
    ]);

    // Same reasoning as the Kanban card test above: the field's NAME
    // legitimately appears in this page's own New Lead modal — only the
    // stored VALUE must never leak into the table itself.
    Livewire::actingAs($clientAdmin)
        ->test(Index::class)->call('completeInitialLoad')
        ->assertDontSee('Word of mouth');
});

test('rendering the custom fields card does not N+1 per-field lookups', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $definitions = LeadCustomFieldDefinition::factory()->count(5)->create(['tenant_id' => $tenant->id]);

    foreach ($definitions as $definition) {
        LeadCustomFieldValue::factory()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'lead_custom_field_definition_id' => $definition->id,
        ]);
    }

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = $component->instance()->customFieldDisplayRows();
    $rows->each(fn (array $row) => $row['definition']->name);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($rows)->toHaveCount(5);
    expect($queryCount)->toBeLessThanOrEqual(3);
});
