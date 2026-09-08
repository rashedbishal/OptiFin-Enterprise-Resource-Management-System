<?php

use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use App\Livewire\Tenant\LeadScoring\Index;
use App\Models\LeadScoringRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('a client admin can create a scoring rule', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'High budget')
        ->set('field', LeadScoringField::EstimatedBudget->value)
        ->set('operator', LeadScoringOperator::GreaterThanOrEqual->value)
        ->set('value', '1000000')
        ->set('points', '20')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasNoErrors();

    $rule = LeadScoringRule::where('tenant_id', $tenant->id)->firstOrFail();
    expect($rule->name)->toBe('High budget');
    expect($rule->field)->toBe('estimated_budget');
    expect($rule->operator)->toBe('>=');
    expect((float) $rule->value)->toBe(1000000.0);
    expect($rule->points)->toBe(20);
    expect($rule->is_active)->toBeTrue();
});

test('a client admin can create a rule with negative points and zero points', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Stale lead')
        ->set('field', LeadScoringField::DaysSinceCreated->value)
        ->set('operator', LeadScoringOperator::GreaterThanOrEqual->value)
        ->set('value', '30')
        ->set('points', '-10')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasNoErrors();

    expect(LeadScoringRule::where('tenant_id', $tenant->id)->firstOrFail()->points)->toBe(-10);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Neutral')
        ->set('field', LeadScoringField::HasEmail->value)
        ->set('operator', LeadScoringOperator::Equals->value)
        ->set('value', '1')
        ->set('points', '0')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasNoErrors();

    expect(LeadScoringRule::where('tenant_id', $tenant->id)->where('name', 'Neutral')->firstOrFail()->points)->toBe(0);
});

test('an invalid field/operator combination is rejected server-side even if submitted directly', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Bad combo')
        ->set('field', LeadScoringField::EstimatedBudget->value)
        ->set('operator', LeadScoringOperator::In->value)
        ->set('value', '1000')
        ->set('points', '10')
        ->set('sortOrder', '0')
        ->call('save')
        ->assertHasErrors('operator');

    expect(LeadScoringRule::where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a client admin can edit, deactivate, and delete a scoring rule', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rule = LeadScoringRule::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Original', 'points' => 5]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $rule->id)
        ->set('name', 'Renamed')
        ->set('points', '25')
        ->call('save')
        ->assertHasNoErrors();

    expect($rule->fresh()->name)->toBe('Renamed');
    expect($rule->fresh()->points)->toBe(25);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('toggleActive', $rule->id);

    expect($rule->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('deleteConfirmed'); // no confirmingDeleteId set yet -> no-op

    expect($rule->fresh())->not->toBeNull();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $rule->id)
        ->call('deleteConfirmed');

    expect(LeadScoringRule::find($rule->id))->toBeNull();
});

test('a client user without admin rights cannot access the scoring rules page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertForbidden();
});

test('a tenant\'s scoring rules never appear for, or can be touched by, another tenant', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();

    $ruleA = LeadScoringRule::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A rule']);
    LeadScoringRule::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B rule']);

    $component = Livewire::actingAs($adminA)->test(Index::class);

    $component->assertSee('Tenant A rule')->assertDontSee('Tenant B rule');

    expect(fn () => $component->call('edit', LeadScoringRule::where('tenant_id', $tenantB->id)->firstOrFail()->id))
        ->toThrow(ModelNotFoundException::class);

    expect($ruleA->fresh())->not->toBeNull();
});
