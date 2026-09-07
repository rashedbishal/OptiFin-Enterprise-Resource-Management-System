<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Lead;
use Livewire\Livewire;

test('the pipeline stepper is a wrapping numbered row with no horizontal scrollbar at any viewport', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('qualified')->id]);

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    expect(substr_count($html, __('Status Pipeline Progression')))->toBe(1);
    expect($html)->toContain('flex flex-wrap items-center gap-y-3');
    expect($html)->not->toContain('overflow-x-auto');

    // Every stage's label appears, connected by one chevron between each
    // pair of stages — no stage silently dropped, and it reads as one
    // connected sequence rather than a grid of disconnected boxes.
    $pipeline = $tenant->fresh()->leadStatuses;
    foreach ($pipeline as $statusCase) {
        expect(substr_count($html, $statusCase->label()))->toBeGreaterThanOrEqual(1);
    }
    // Flux icons compile to inline SVG (no "chevron-right" string survives
    // in the output), so the connector's own distinguishing class is what's
    // actually searchable here.
    expect(substr_count($html, 'mx-2 size-4 shrink-0 text-zinc-300'))->toBe($pipeline->count() - 1);
});

test('the current pipeline stage\'s number is highlighted, others are plain', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $pipeline = $tenant->fresh()->leadStatuses;
    $currentStatus = $pipeline[2];
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => $currentStatus->id]);

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    expect(substr_count($html, $currentStatus->cardBackgroundClasses()))->toBe(1);
});

test('only the current pipeline stage\'s circle pulses, in that status\'s own color', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $pipeline = $tenant->fresh()->leadStatuses;
    $currentStatus = $pipeline[2];
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => $currentStatus->id]);

    $html = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad')->html();

    expect(substr_count($html, 'motion-safe:animate-ping'))->toBe(1);
    expect(substr_count($html, $currentStatus->pulseRingClasses()))->toBe(1);
});
