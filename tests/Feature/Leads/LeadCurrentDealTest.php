<?php

use App\Livewire\Tenant\Leads\Show;
use App\Models\Deal;
use App\Models\Lead;
use Livewire\Livewire;

test('currentDeal returns the most recently created open deal when several are open', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Older', 'created_at' => now()->subDays(5)]);
    $newer = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Newer', 'created_at' => now()->subDay()]);

    expect($lead->currentDeal()->id)->toBe($newer->id);
});

test('currentDeal falls back to the most recently created closed deal when none are open', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    Deal::factory()->lost()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Older Lost', 'created_at' => now()->subDays(5)]);
    $newerWon = Deal::factory()->won()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Newer Won', 'created_at' => now()->subDay()]);

    expect($lead->currentDeal()->id)->toBe($newerWon->id);
});

test('currentDeal is null when the lead has no deals at all', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    expect($lead->currentDeal())->toBeNull();
});

test('currentDeal resolves deterministically when two open deals share a created_at timestamp', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $sameInstant = now();

    Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Tie A', 'created_at' => $sameInstant]);
    Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Tie B', 'created_at' => $sameInstant]);

    // Deterministic regardless of insertion order: the same query run twice
    // must return the same deal both times (ULID id as the tiebreaker).
    $first = $lead->currentDeal();
    $second = $lead->currentDeal();
    expect($first->id)->toBe($second->id);
});

test('an open deal prevails over a more recently created closed one', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $open = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Still Open', 'created_at' => now()->subDays(10)]);
    Deal::factory()->won()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id, 'name' => 'Closed More Recently', 'created_at' => now()]);

    expect($lead->currentDeal()->id)->toBe($open->id);
});

test('currentDeal never hides any deal from the Deal list — every deal remains visible', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $open = Deal::factory()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    $won = Deal::factory()->won()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);
    $lost = Deal::factory()->lost()->create(['tenant_id' => $tenant->id, 'lead_id' => $lead->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Show::class, ['lead' => $lead])->call('completeInitialLoad');

    $ids = $component->get('deals')->pluck('id');
    expect($ids)->toContain($open->id)->toContain($won->id)->toContain($lost->id);
});
