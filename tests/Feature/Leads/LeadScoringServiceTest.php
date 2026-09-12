<?php

use App\Enums\LeadPriority;
use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadScoreLog;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use App\Models\LeadStatusLog;
use App\Services\LeadScoringService;

function makeScoringRule(string $tenantId, array $overrides = []): LeadScoringRule
{
    return LeadScoringRule::factory()->create(array_merge([
        'tenant_id' => $tenantId,
        'field' => LeadScoringField::Priority->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => 'urgent',
        'points' => 10,
        'is_active' => true,
    ], $overrides));
}

test('a tenant with zero active scoring rules produces a null score, not a computed 0', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    expect($score)->toBeNull();
    expect($lead->fresh()->lead_score)->toBeNull();
    expect(LeadScoreLog::query()->where('lead_id', $lead->id)->count())->toBe(0);
});

test('at least one active rule always produces a real integer score, including a legitimate computed 0', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    // Rule exists but will never match this lead (priority stays default Medium).
    makeScoringRule($tenant->id, ['field' => LeadScoringField::Priority->value, 'value' => 'urgent']);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Medium]);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    expect($score)->toBe(0);
    expect($lead->fresh()->lead_score)->toBe(0);
});

test('numeric operators evaluate correctly', function (string $operator, string $value, string $leadBudget, bool $shouldMatch) {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => LeadScoringField::EstimatedBudget->value, 'operator' => $operator, 'value' => $value, 'points' => 10]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'estimated_budget' => $leadBudget]);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    expect($score)->toBe($shouldMatch ? 10 : 0);
})->with([
    'greater than or equal, matches' => ['>=', '1000', '1500.00', true],
    'greater than or equal, exact match' => ['>=', '1000', '1000.00', true],
    'greater than or equal, no match' => ['>=', '1000', '500.00', false],
    'less than or equal, matches' => ['<=', '1000', '500.00', true],
    'less than or equal, no match' => ['<=', '1000', '1500.00', false],
    'greater than, matches' => ['>', '1000', '1500.00', true],
    'greater than, boundary no match' => ['>', '1000', '1000.00', false],
    'less than, matches' => ['<', '1000', '500.00', true],
    'equals, matches' => ['=', '1000', '1000.00', true],
    'not equals, matches' => ['!=', '1000', '500.00', true],
    'not equals, no match' => ['!=', '1000', '1000.00', false],
]);

test('enum operators evaluate correctly, including in/not_in lists', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::High]);

    makeScoringRule($tenant->id, ['name' => 'in', 'field' => LeadScoringField::Priority->value, 'operator' => LeadScoringOperator::In->value, 'value' => ['high', 'urgent'], 'points' => 5]);
    makeScoringRule($tenant->id, ['name' => 'not_in', 'field' => LeadScoringField::Priority->value, 'operator' => LeadScoringOperator::NotIn->value, 'value' => ['low'], 'points' => 5]);
    makeScoringRule($tenant->id, ['name' => 'no-match-in', 'field' => LeadScoringField::Priority->value, 'operator' => LeadScoringOperator::In->value, 'value' => ['low'], 'points' => 100]);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    expect($score)->toBe(10);
});

test('boolean fields (has_email/has_phone) evaluate correctly', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => LeadScoringField::HasEmail->value, 'operator' => LeadScoringOperator::Equals->value, 'value' => true, 'points' => 5]);
    makeScoringRule($tenant->id, ['name' => 'no-phone', 'field' => LeadScoringField::HasPhone->value, 'operator' => LeadScoringOperator::Equals->value, 'value' => true, 'points' => 5]);

    $leadWithEmail = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.com', 'phone' => null]);

    $score = app(LeadScoringService::class)->computeFor($leadWithEmail, 'manual');

    expect($score)->toBe(5);
});

test('points may be positive, negative, or zero, and all are summed', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['name' => 'positive', 'field' => LeadScoringField::HasEmail->value, 'operator' => '=', 'value' => true, 'points' => 20]);
    makeScoringRule($tenant->id, ['name' => 'negative', 'field' => LeadScoringField::HasPhone->value, 'operator' => '=', 'value' => false, 'points' => -5]);
    makeScoringRule($tenant->id, ['name' => 'zero', 'field' => LeadScoringField::WinProbability->value, 'operator' => '>=', 'value' => '0', 'points' => 0]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.com', 'phone' => null, 'win_probability' => 10]);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    expect($score)->toBe(15);
});

test('the final score is clamped to 100 even when matching rules sum higher', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['name' => 'a', 'field' => LeadScoringField::HasEmail->value, 'operator' => '=', 'value' => true, 'points' => 80]);
    makeScoringRule($tenant->id, ['name' => 'b', 'field' => LeadScoringField::HasPhone->value, 'operator' => '=', 'value' => true, 'points' => 80]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.com', 'phone' => '15551234567']);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    expect($score)->toBe(100);

    $log = LeadScoreLog::query()->where('lead_id', $lead->id)->latest('computed_at')->first();
    expect($log->raw_total)->toBe(160);
    expect($log->score)->toBe(100);
});

test('the final score is clamped to 0 even when matching rules sum lower', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => LeadScoringField::HasEmail->value, 'operator' => '=', 'value' => true, 'points' => -50]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.com']);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    expect($score)->toBe(0);

    $log = LeadScoreLog::query()->where('lead_id', $lead->id)->latest('computed_at')->first();
    expect($log->raw_total)->toBe(-50);
    expect($log->score)->toBe(0);
});

test('recalculating an unchanged lead against unchanged rules always produces the same score, never accumulates', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => LeadScoringField::HasEmail->value, 'operator' => '=', 'value' => true, 'points' => 10]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.com']);

    $scoringService = app(LeadScoringService::class);

    $first = $scoringService->computeFor($lead->fresh(), 'manual');
    $second = $scoringService->computeFor($lead->fresh(), 'manual');
    $third = $scoringService->computeFor($lead->fresh(), 'status_priority_changed');

    expect([$first, $second, $third])->toBe([10, 10, 10]);
});

test('a meaningful score change writes a new log, but an unchanged recomputation does not', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rule = makeScoringRule($tenant->id, ['field' => LeadScoringField::HasEmail->value, 'operator' => '=', 'value' => true, 'points' => 10]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.com']);

    $scoringService = app(LeadScoringService::class);

    $scoringService->computeFor($lead->fresh(), 'lead_received');
    expect(LeadScoreLog::query()->where('lead_id', $lead->id)->count())->toBe(1);

    // Identical recomputation: no new log.
    $scoringService->computeFor($lead->fresh(), 'scheduled_sweep');
    expect(LeadScoreLog::query()->where('lead_id', $lead->id)->count())->toBe(1);

    // A real change: a second log appears.
    $rule->update(['points' => 20]);
    $scoringService->computeFor($lead->fresh(), 'manual');
    expect(LeadScoreLog::query()->where('lead_id', $lead->id)->count())->toBe(2);
});

test('the log breakdown is a full snapshot of every matched rule', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, [
        'name' => 'High budget',
        'field' => LeadScoringField::EstimatedBudget->value,
        'operator' => LeadScoringOperator::GreaterThanOrEqual->value,
        'value' => '1000000',
        'points' => 20,
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'estimated_budget' => '1500000.00']);

    app(LeadScoringService::class)->computeFor($lead, 'manual');

    $log = LeadScoreLog::query()->where('lead_id', $lead->id)->latest('computed_at')->first();

    expect($log->breakdown)->toHaveCount(1);
    expect($log->breakdown[0]['rule_name'])->toBe('High budget');
    expect($log->breakdown[0]['field'])->toBe('estimated_budget');
    expect($log->breakdown[0]['operator'])->toBe('>=');
    expect((float) $log->breakdown[0]['matched_value'])->toBe(1500000.0);
    expect($log->breakdown[0]['points'])->toBe(20);
});

test('a historical log stays fully readable after the rule that produced it is deleted', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rule = makeScoringRule($tenant->id, ['name' => 'Urgent', 'field' => LeadScoringField::Priority->value, 'value' => 'urgent', 'points' => 15]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Urgent]);

    app(LeadScoringService::class)->computeFor($lead, 'manual');
    $rule->delete();

    $log = LeadScoreLog::query()->where('lead_id', $lead->id)->latest('computed_at')->first();

    expect($log->breakdown[0]['rule_name'])->toBe('Urgent');
    expect($log->breakdown[0]['points'])->toBe(15);
});

test('an inactive rule is excluded from scoring entirely', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => LeadScoringField::Priority->value, 'value' => 'urgent', 'points' => 50, 'is_active' => false]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Urgent]);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    // No ACTIVE rule at all -> null, not a computed 0, since the only rule
    // that exists for this tenant is inactive.
    expect($score)->toBeNull();
});

test('a rule with an unsupported field or operator is safely skipped, never throws', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => 'not_a_real_field', 'operator' => '=', 'value' => 'x', 'points' => 999]);
    makeScoringRule($tenant->id, ['name' => 'bad-operator', 'field' => LeadScoringField::Priority->value, 'operator' => 'not_a_real_operator', 'value' => 'urgent', 'points' => 999]);
    makeScoringRule($tenant->id, [
        'name' => 'valid',
        'field' => LeadScoringField::Priority->value,
        'operator' => LeadScoringOperator::Equals->value,
        'value' => 'urgent',
        'points' => 10,
    ]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'priority' => LeadPriority::Urgent]);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    expect($score)->toBe(10);
});

test('a numeric-kind field paired with an operator only valid for enum fields is skipped, never throws', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    // "in" is not a valid operator for a Numeric-kind field per
    // LeadScoringField::allowedOperators() — a rule stored with this
    // invalid combination (e.g. by a future bug or direct DB edit) must
    // not crash recalculation.
    makeScoringRule($tenant->id, ['field' => LeadScoringField::EstimatedBudget->value, 'operator' => LeadScoringOperator::In->value, 'value' => ['1000'], 'points' => 999]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'estimated_budget' => '1000.00']);

    $score = app(LeadScoringService::class)->computeFor($lead, 'manual');

    expect($score)->toBe(0);
});

test('days_since_created is evaluated against the current time, not stored/cumulative', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => LeadScoringField::DaysSinceCreated->value, 'operator' => LeadScoringOperator::GreaterThanOrEqual->value, 'value' => '14', 'points' => 10]);

    $freshLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()]);
    $oldLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(20)]);

    expect(app(LeadScoringService::class)->computeFor($freshLead, 'manual'))->toBe(0);
    expect(app(LeadScoringService::class)->computeFor($oldLead, 'manual'))->toBe(10);
});

test('equals on a numeric field compares numerically, not lexicographically', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    // Stored as a bare integer-looking float (1500.0 round-trips through
    // json_encode as "1500", no decimal point) — the rule's raw text is
    // "1500", the lead's own decimal(2) column always renders "1500.00".
    // A naive (string) cast would compare "1500" against "1500" and still
    // pass by coincidence; this specifically exercises the is_numeric()
    // branch rather than relying on that coincidence.
    makeScoringRule($tenant->id, ['field' => LeadScoringField::EstimatedBudget->value, 'operator' => '=', 'value' => '1500', 'points' => 10]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'estimated_budget' => '1500.00']);

    expect(app(LeadScoringService::class)->computeFor($lead, 'manual'))->toBe(10);
});

test('not_equals on a numeric field compares numerically', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => LeadScoringField::EstimatedBudget->value, 'operator' => '!=', 'value' => '1500', 'points' => 10]);
    $matching = Lead::factory()->create(['tenant_id' => $tenant->id, 'estimated_budget' => '2000.00']);
    $nonMatching = Lead::factory()->create(['tenant_id' => $tenant->id, 'estimated_budget' => '1500.00']);

    expect(app(LeadScoringService::class)->computeFor($matching, 'manual'))->toBe(10);
    expect(app(LeadScoringService::class)->computeFor($nonMatching, 'manual'))->toBe(0);
});

test('status field equals/mismatch and in/not_in evaluate correctly', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('qualified')->id]);

    makeScoringRule($tenant->id, ['name' => 'match', 'field' => LeadScoringField::Status->value, 'operator' => '=', 'value' => 'qualified', 'points' => 5]);
    makeScoringRule($tenant->id, ['name' => 'mismatch', 'field' => LeadScoringField::Status->value, 'operator' => '=', 'value' => 'won', 'points' => 100]);
    makeScoringRule($tenant->id, ['name' => 'in', 'field' => LeadScoringField::Status->value, 'operator' => 'in', 'value' => ['qualified', 'proposal_sent'], 'points' => 5]);
    makeScoringRule($tenant->id, ['name' => 'not_in', 'field' => LeadScoringField::Status->value, 'operator' => 'not_in', 'value' => ['won', 'lost'], 'points' => 5]);

    expect(app(LeadScoringService::class)->computeFor($lead, 'manual'))->toBe(15);
});

test('source_type field equals/mismatch evaluate correctly', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $source = LeadSource::factory()->create(['tenant_id' => $tenant->id, 'type' => LeadSourceType::Whatsapp]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

    makeScoringRule($tenant->id, ['name' => 'match', 'field' => LeadScoringField::SourceType->value, 'operator' => '=', 'value' => 'whatsapp', 'points' => 5]);
    makeScoringRule($tenant->id, ['name' => 'mismatch', 'field' => LeadScoringField::SourceType->value, 'operator' => '=', 'value' => 'email', 'points' => 100]);

    expect(app(LeadScoringService::class)->computeFor($lead, 'manual'))->toBe(5);
});

test('days_since_last_status_change falls back to created_at when the lead has never had a status change logged', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => LeadScoringField::DaysSinceLastStatusChange->value, 'operator' => '>=', 'value' => '10', 'points' => 10]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(15)]);

    expect(app(LeadScoringService::class)->computeFor($lead, 'manual'))->toBe(10);
});

test('days_since_last_status_change uses the most recent LeadStatusLog when one exists, not created_at', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => LeadScoringField::DaysSinceLastStatusChange->value, 'operator' => '<=', 'value' => '5', 'points' => 10]);

    // Created long ago, but its status changed recently — the recent
    // change, not the old created_at, must drive the calculation.
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(60)]);
    LeadStatusLog::factory()->create(['lead_id' => $lead->id, 'changed_by' => $clientAdmin->id, 'created_at' => now()->subDays(2)]);

    expect(app(LeadScoringService::class)->computeFor($lead, 'manual'))->toBe(10);
});

test('days_since_last_status_change ignores an older status log in favor of the most recent one', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    makeScoringRule($tenant->id, ['field' => LeadScoringField::DaysSinceLastStatusChange->value, 'operator' => '<=', 'value' => '5', 'points' => 10]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    LeadStatusLog::factory()->create(['lead_id' => $lead->id, 'changed_by' => $clientAdmin->id, 'created_at' => now()->subDays(30)]);
    LeadStatusLog::factory()->create(['lead_id' => $lead->id, 'changed_by' => $clientAdmin->id, 'created_at' => now()->subDays(1)]);

    expect(app(LeadScoringService::class)->computeFor($lead, 'manual'))->toBe(10);
});

test('scoring rules and scores never cross tenant boundaries', function () {
    ['tenant' => $tenantA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();

    makeScoringRule($tenantA->id, ['field' => LeadScoringField::Priority->value, 'value' => 'urgent', 'points' => 50]);

    $leadInB = Lead::factory()->create(['tenant_id' => $tenantB->id, 'priority' => LeadPriority::Urgent]);

    // Tenant B has no rules of its own -> null, tenant A's rule must never apply.
    $score = app(LeadScoringService::class)->computeFor($leadInB, 'manual');

    expect($score)->toBeNull();
});
