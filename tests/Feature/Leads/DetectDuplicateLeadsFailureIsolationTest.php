<?php

use App\Models\Lead;
use App\Services\LeadDuplicateDetectionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * F12 remediation (forensic audit Batch C) — before this fix, one lead
 * throwing inside the chunkById() loop would abort leads:detect-duplicates
 * entirely, silently leaving every later lead in the chunk (and every later
 * chunk) unscanned for the day. These tests exercise the resulting per-lead
 * try/catch in App\Console\Commands\DetectDuplicateLeads::handle().
 */
test('a duplicate-detection failure on one lead is isolated: earlier and later leads are still scanned, and the command still exits successfully', function () {
    $leads = Lead::factory()->count(3)->create();
    $failing = $leads[1];

    $this->mock(LeadDuplicateDetectionService::class)
        ->shouldReceive('detectFor')
        ->times(3)
        ->andReturnUsing(function (Lead $lead) use ($failing) {
            if ($lead->is($failing)) {
                throw new RuntimeException('a transient duplicate-detection failure');
            }

            return new Collection;
        });

    Log::shouldReceive('error')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'leads:detect-duplicates')
            && str_contains($message, "lead [{$failing->id}]")
            && str_contains($message, "tenant [{$failing->tenant_id}]")
            && str_contains($message, 'a transient duplicate-detection failure'),
    );
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, '1 of 3'));

    $this->artisan('leads:detect-duplicates')
        ->assertSuccessful()
        ->expectsOutputToContain('Scanned 2 lead(s) for possible duplicates.');
});

test('when every lead succeeds, no failure is logged and no summary warning fires', function () {
    Lead::factory()->count(2)->create();

    $this->mock(LeadDuplicateDetectionService::class)->shouldReceive('detectFor')->twice()->andReturn(new Collection);

    Log::shouldReceive('warning')->never();
    Log::shouldReceive('error')->never();

    $this->artisan('leads:detect-duplicates')
        ->assertSuccessful()
        ->expectsOutputToContain('Scanned 2 lead(s) for possible duplicates.');
});

test('a merged-away lead is never attempted at all, so it can never be the isolated failure', function () {
    $survivor = Lead::factory()->create();
    Lead::factory()->create(['merged_into_lead_id' => $survivor->id]);

    $this->mock(LeadDuplicateDetectionService::class)
        ->shouldReceive('detectFor')
        ->once()
        ->with(Mockery::on(fn (Lead $lead): bool => $lead->is($survivor)))
        ->andReturn(new Collection);

    $this->artisan('leads:detect-duplicates')
        ->assertSuccessful()
        ->expectsOutputToContain('Scanned 1 lead(s) for possible duplicates.');
});
