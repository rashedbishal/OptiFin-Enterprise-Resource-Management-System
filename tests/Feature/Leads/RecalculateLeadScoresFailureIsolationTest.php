<?php

use App\Models\Lead;
use App\Services\LeadScoringService;
use Illuminate\Support\Facades\Log;

/**
 * F12 remediation (forensic audit Batch C) — before this fix, one lead
 * throwing inside the chunkById() loop would abort leads:recalculate-scores
 * entirely, silently leaving every later lead in the chunk (and every later
 * chunk) unscored for the day. These tests exercise the resulting per-lead
 * try/catch in App\Console\Commands\RecalculateLeadScores::handle().
 */
test('a scoring failure on one lead is isolated: earlier and later leads are still recalculated, and the command still exits successfully', function () {
    $leads = Lead::factory()->count(3)->create();
    $failing = $leads[1];

    $this->mock(LeadScoringService::class)
        ->shouldReceive('computeFor')
        ->times(3)
        ->andReturnUsing(function (Lead $lead, string $trigger) use ($failing) {
            expect($trigger)->toBe('scheduled_sweep');

            if ($lead->is($failing)) {
                throw new RuntimeException('a transient scoring failure');
            }

            return 50;
        });

    Log::shouldReceive('error')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'leads:recalculate-scores')
            && str_contains($message, "lead [{$failing->id}]")
            && str_contains($message, "tenant [{$failing->tenant_id}]")
            && str_contains($message, 'a transient scoring failure'),
    );
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, '1 of 3'));

    $this->artisan('leads:recalculate-scores')
        ->assertSuccessful()
        ->expectsOutputToContain('Recalculated score for 2 lead(s).');
});

test('when every lead succeeds, no failure is logged and no summary warning fires', function () {
    Lead::factory()->count(2)->create();

    $this->mock(LeadScoringService::class)->shouldReceive('computeFor')->twice()->andReturn(10);

    Log::shouldReceive('warning')->never();
    Log::shouldReceive('error')->never();

    $this->artisan('leads:recalculate-scores')
        ->assertSuccessful()
        ->expectsOutputToContain('Recalculated score for 2 lead(s).');
});

test('a merged-away lead is never attempted at all, so it can never be the isolated failure', function () {
    $survivor = Lead::factory()->create();
    Lead::factory()->create(['merged_into_lead_id' => $survivor->id]);

    $this->mock(LeadScoringService::class)
        ->shouldReceive('computeFor')
        ->once()
        ->with(Mockery::on(fn (Lead $lead): bool => $lead->is($survivor)), 'scheduled_sweep')
        ->andReturn(1);

    $this->artisan('leads:recalculate-scores')
        ->assertSuccessful()
        ->expectsOutputToContain('Recalculated score for 1 lead(s).');
});
