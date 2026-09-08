<?php

use App\Actions\Tenant\CreateManualLead;
use App\Enums\LeadPriority;
use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use App\Events\LeadReceived;
use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use App\Models\LeadScoringRule;
use App\Services\LeadMergeService;
use App\Services\LeadScoringService;
use Livewire\Livewire;

function tenantScoredForUrgentPriority(): array
{
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::Priority->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => 'urgent',
        'points' => 15,
        'is_active' => true,
    ]);

    return ['tenant' => $tenant, 'clientAdmin' => $clientAdmin];
}

test('a lead received via LeadReceived gets its initial score computed', function () {
    ['tenant' => $tenant] = tenantScoredForUrgentPriority();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Urgent, 'lead_score' => null]);

    event(new LeadReceived($lead));

    expect($lead->fresh()->lead_score)->toBe(15);
});

test('a manually created lead gets an initial score even though it never fires LeadReceived', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantScoredForUrgentPriority();

    $lead = app(CreateManualLead::class)->handle($tenant, [
        'name' => 'Jane Doe', 'company' => null, 'website' => null, 'industry' => null, 'location' => null,
        'service_interest' => null, 'email' => null, 'phone' => null, 'message' => null,
        'status_id' => $tenant->initialLeadStatus()->id, 'priority' => LeadPriority::Urgent,
        'estimated_budget' => null, 'deal_value' => null, 'win_probability' => null, 'next_follow_up_at' => null,
    ], $clientAdmin, null);

    expect($lead->lead_score)->toBe(15);
});

test('changing a lead\'s status recomputes its score', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::Status->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => 'contacted',
        'points' => 12,
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('contacted')->id)
        ->call('updateStatus');

    expect($lead->fresh()->lead_score)->toBe(12);
});

test('changing only priority (no status change) still recomputes the score', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantScoredForUrgentPriority();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Low, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('new')->id)
        ->set('priority', LeadPriority::Urgent->value)
        ->call('updateStatus');

    expect($lead->fresh()->lead_score)->toBe(15);
});

test('the merged status+assignment form also recomputes the score on a status change', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::Status->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => 'contacted',
        'points' => 8,
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('status', leadStatus('contacted')->id)
        ->call('updateStatusAndAssignment');

    expect($lead->fresh()->lead_score)->toBe(8);
});

test('editing a scoring-relevant detail field (budget) recomputes the score', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::EstimatedBudget->value,
        'operator' => LeadScoringOperator::GreaterThanOrEqual->value,
        'value' => '1000',
        'points' => 20,
    ]);
    $currencyId = $tenant->currencies()->first()->id;
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'estimated_budget' => null, 'lead_score_computed_at' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('leadEstimatedBudget', '5000')
        ->set('leadEstimatedBudgetCurrencyId', $currencyId)
        ->call('updateDeal')
        ->assertHasNoErrors();

    expect($lead->fresh()->lead_score)->toBe(20);
    expect($lead->fresh()->lead_score_computed_at)->not->toBeNull();
});

test('editing only company details (no scoring-relevant field) does not trigger a recompute', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::HasEmail->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => true,
        'points' => 10,
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.com', 'company' => null, 'website' => null, 'industry' => null, 'location' => null]);
    app(LeadScoringService::class)->computeFor($lead, 'manual');
    $computedAtBefore = $lead->fresh()->lead_score_computed_at;

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead->fresh()])->call('completeInitialLoad')
        ->set('leadCompany', 'Acme Co')
        ->set('leadWebsite', 'https://acme.example.com')
        ->set('leadIndustry', 'Retail')
        ->set('leadLocation', 'Dhaka')
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($lead->fresh()->company)->toBe('Acme Co');
    expect($lead->fresh()->lead_score_computed_at->equalTo($computedAtBefore))->toBeTrue();
});

test('adding a note never triggers a recompute', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'lead_score_computed_at' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('newNote', 'Called them back.')
        ->call('addNote');

    expect($lead->fresh()->lead_score_computed_at)->toBeNull();
});

test('assigning a lead never triggers a recompute', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'lead_score_computed_at' => null]);

    Livewire::actingAs($clientAdmin)
        ->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')
        ->set('assigneeId', $rep->id)
        ->call('assign');

    expect($lead->fresh()->lead_score_computed_at)->toBeNull();
});

test('a merge that pulls a scoring-relevant field from the loser recomputes the survivor\'s score', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadScoringRule::factory()->create([
        'tenant_id' => $tenant->id,
        'field' => LeadScoringField::HasEmail->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => true,
        'points' => 10,
    ]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => null, 'lead_score_computed_at' => null]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'loser@example.com']);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, ['email' => 'loser']);

    expect($survivor->fresh()->email)->toBe('loser@example.com');
    expect($survivor->fresh()->lead_score)->toBe(10);
});

test('a merge that pulls no scoring-relevant field never recomputes the survivor\'s score', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'Old Co', 'lead_score_computed_at' => null]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'New Co']);

    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, ['company' => 'loser']);

    expect($survivor->fresh()->company)->toBe('New Co');
    expect($survivor->fresh()->lead_score_computed_at)->toBeNull();
});

test('the daily sweep recomputes every tenant\'s leads and skips merged-away leads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantScoredForUrgentPriority();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Urgent, 'lead_score' => null]);
    $survivor = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Urgent]);
    $loser = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Urgent]);
    app(LeadMergeService::class)->merge($survivor, $loser, $clientAdmin, []);

    $this->artisan('leads:recalculate-scores')->assertSuccessful();

    expect($lead->fresh()->lead_score)->toBe(15);
    expect($loser->fresh()->lead_score_computed_at)->toBeNull();
});
