<?php

use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadScoringRule;
use App\Services\LeadScoringService;
use Livewire\Livewire;

test('the lead detail page shows "Not scored" when the tenant has no active scoring rules', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSee('Not scored');
});

test('the lead detail page shows the computed score and its breakdown', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'High budget',
        'field' => LeadScoringField::EstimatedBudget->value,
        'operator' => LeadScoringOperator::GreaterThanOrEqual->value,
        'value' => '1000',
        'points' => 20,
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'estimated_budget' => '5000.00']);
    app(LeadScoringService::class)->computeFor($lead, 'manual');

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead->fresh()])->call('completeInitialLoad')
        ->assertSee('20')
        ->assertSee('High budget')
        ->assertDontSee('Not scored');
});

test('a client admin can manually recalculate a lead\'s score from the detail page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::HasEmail->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => true,
        'points' => 10,
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.com', 'lead_score' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->call('recalculateScore')
        ->assertDispatched('toast-show');

    expect($lead->fresh()->lead_score)->toBe(10);
});

test('a client user without admin rights cannot manually recalculate a lead\'s score', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'lead_score' => null]);

    Livewire::actingAs($rep)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertDontSeeHtml('wire:click="recalculateScore"')
        ->call('recalculateScore')
        ->assertDispatched('toast-show');

    expect($lead->fresh()->lead_score)->toBeNull();
});

test('the hero card labels win_probability "Win Probability", not "Lead Score", and its value is unchanged', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'win_probability' => 42]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->assertSee('Win Probability')
        ->assertSee('42%');

    expect($lead->fresh()->win_probability)->toBe(42);
});
