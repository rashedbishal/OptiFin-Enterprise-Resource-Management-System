<?php

use App\Enums\WebsiteFormFixedField;
use App\Livewire\Tenant\LeadCustomFields\Index as LeadCustomFieldsIndex;
use App\Livewire\Tenant\WebsiteForms\Index;
use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\WebsiteForm;
use Livewire\Livewire;

test('a client admin can create a website form and is shown the plaintext token exactly once', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Contact Us')
        ->set('fixedFields', [WebsiteFormFixedField::Email->value])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showTokenModal', true);

    $form = WebsiteForm::where('tenant_id', $tenant->id)->firstOrFail();
    expect($form->name)->toBe('Contact Us');
    expect($form->fields()->count())->toBe(1);
    expect($form->leadSource)->not->toBeNull();
    expect($form->leadSource->tenant_id)->toBe($tenant->id);
});

test('a tenant can have multiple website forms sharing the same lead source', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);

    $component->call('create')->set('name', 'Form A')->call('save')->assertHasNoErrors();
    $component->call('create')->set('name', 'Form B')->call('save')->assertHasNoErrors();

    $forms = WebsiteForm::where('tenant_id', $tenant->id)->get();
    expect($forms)->toHaveCount(2);
    expect($forms->pluck('lead_source_id')->unique())->toHaveCount(1);
});

test('editing a website form replaces its field configuration', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budget', 'key' => 'budget']);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);
    $component->call('create')
        ->set('name', 'Contact Us')
        ->set('fixedFields', [WebsiteFormFixedField::Email->value, WebsiteFormFixedField::Phone->value])
        ->call('save')
        ->assertHasNoErrors();

    $form = WebsiteForm::where('tenant_id', $tenant->id)->firstOrFail();
    expect($form->fields()->count())->toBe(2);

    $component->call('edit', $form->id)
        ->set('fixedFields', [WebsiteFormFixedField::Email->value])
        ->set('customFieldIds', [$definition->id])
        ->call('save')
        ->assertHasNoErrors();

    $form->refresh();
    expect($form->fields()->count())->toBe(2);
    expect($form->fields()->where('field_type', 'fixed')->pluck('fixed_field')->all())->toBe(['email']);
    expect($form->fields()->where('field_type', 'custom')->pluck('lead_custom_field_definition_id')->all())->toBe([$definition->id]);
});

test('an inactive custom field definition is not offered when configuring a form', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadCustomFieldDefinition::factory()->inactive()->create(['tenant_id' => $tenant->id, 'name' => 'Old Field', 'key' => 'old-field']);

    $ids = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->instance()
        ->activeCustomFieldDefinitions
        ->pluck('id');

    expect($ids)->toBeEmpty();
});

test('regenerating a website form token invalidates the old token immediately', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);
    $component->call('create')->set('name', 'Contact Us')->call('save')->assertHasNoErrors();
    $oldToken = $component->get('revealedToken');
    $form = WebsiteForm::where('tenant_id', $tenant->id)->firstOrFail();

    test()->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, $oldToken]), [
        'name' => 'Jane Doe',
    ])->assertOk();

    $component->call('confirmRegenerate', $form->id)
        ->call('regenerateConfirmed')
        ->assertSet('showTokenModal', true);

    $newToken = $component->get('revealedToken');
    expect($newToken)->not->toBe($oldToken);

    test()->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, $oldToken]), [
        'name' => 'Should Fail',
    ])->assertNotFound();

    test()->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, $newToken]), [
        'name' => 'Should Work',
    ])->assertOk();

    expect(Lead::where('tenant_id', $tenant->id)->where('name', 'Should Fail')->exists())->toBeFalse();
    expect(Lead::where('tenant_id', $tenant->id)->where('name', 'Should Work')->exists())->toBeTrue();
});

test('deleting a website form does not affect leads it already created', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);
    $component->call('create')->set('name', 'Contact Us')->call('save')->assertHasNoErrors();
    $token = $component->get('revealedToken');
    $form = WebsiteForm::where('tenant_id', $tenant->id)->firstOrFail();

    test()->postJson(route('api.webhook.website-form.submit', [$tenant->tenant_code, $token]), [
        'name' => 'Jane Doe',
    ])->assertOk();

    $lead = Lead::where('tenant_id', $tenant->id)->where('name', 'Jane Doe')->firstOrFail();

    $component->call('confirmDelete', $form->id)->call('deleteConfirmed');

    expect(WebsiteForm::find($form->id))->toBeNull();
    expect(Lead::find($lead->id))->not->toBeNull();
});

test('a custom field definition referenced by a website form cannot be deleted', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $definition = LeadCustomFieldDefinition::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Budget', 'key' => 'budget']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Contact Us')
        ->set('customFieldIds', [$definition->id])
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($clientAdmin)
        ->test(LeadCustomFieldsIndex::class)
        ->call('delete', $definition->id);

    expect(LeadCustomFieldDefinition::find($definition->id))->not->toBeNull();
});

test('a client user without lead-management update permission cannot access the website forms page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $clientUser = clientUserWithLeadPermission($tenant, ['view']);

    Livewire::actingAs($clientUser)
        ->test(Index::class)
        ->assertForbidden();
});

test('a website form token is stored only as a sha-256 hash, never in plaintext', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $component = Livewire::actingAs($clientAdmin)->test(Index::class);
    $component->call('create')->set('name', 'Contact Us')->call('save')->assertHasNoErrors();
    $token = $component->get('revealedToken');

    $form = WebsiteForm::where('tenant_id', $tenant->id)->firstOrFail();
    expect($form->token_hash)->not->toBe($token);
    expect($form->token_hash)->toBe(hash('sha256', $token));
});
