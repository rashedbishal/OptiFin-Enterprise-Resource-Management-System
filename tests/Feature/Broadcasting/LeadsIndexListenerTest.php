<?php

use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use Livewire\Livewire;

/**
 * Batch D remediation (forensic audit) — these two tests previously asserted
 * a stale expectation ('$refresh') left over from before Index::getListeners()
 * was changed to route both channel events through dedicated handler methods
 * (onLeadReceived()/onLeadUpdated(), see Index.php:513-540) instead of
 * Livewire's bare `$refresh` magic action. The wiring itself (which channel
 * event maps to which method) and each handler's own documented effect are
 * both still real, current, intended behavior — only the test's expectation
 * of *which* method was stale. Fixed here to assert the current mapping, and
 * strengthened to prove each handler was actually invoked and had its
 * documented effect (not just "the array key exists" or "no exception").
 */
test('the leads index listens for lead.received on its own tenant channel, and onLeadReceived stashes the payload and refreshes a stale board', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad');

    expect($component->instance()->getListeners())
        ->toHaveKey('echo-private:tenant.'.$tenant->id.',.lead.received', 'onLeadReceived');

    // Warm the memoized #[Computed] boardLeads BEFORE the new lead exists,
    // then create it directly in the DB (as the real webhook -> broadcast
    // path would, bypassing this component entirely) — proving
    // onLeadReceived's refreshBoard() call, not just its return value, is
    // what makes the new card appear.
    expect($component->get('boardLeads')->collapse()->pluck('id'))->toBeEmpty();

    $newLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    $component->call('onLeadReceived', ['lead' => ['id' => $newLead->id]]);

    expect($component->instance()->recentlyCreatedLeadId)->toBe($newLead->id);
    expect($component->get('boardLeads')->collapse()->pluck('id'))->toContain($newLead->id);
});

test('the leads index listens for lead.updated on its own tenant channel, and onLeadUpdated refreshes a stale board', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id, 'name' => 'Original Name']);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad');

    expect($component->instance()->getListeners())
        ->toHaveKey('echo-private:tenant.'.$tenant->id.',.lead.updated', 'onLeadUpdated');

    // Warm the memoized #[Computed] boardLeads with the lead's original
    // name, then change it directly in the DB (bypassing this component,
    // as the real webhook -> broadcast path would) — a stale cache would
    // still show the old name after this call if refreshBoard() were not
    // actually invoked.
    expect($component->get('boardLeads')->collapse()->firstWhere('id', $lead->id)->name)->toBe('Original Name');

    $lead->update(['name' => 'Updated Name']);
    $component->call('onLeadUpdated');

    expect($component->get('boardLeads')->collapse()->firstWhere('id', $lead->id)->name)->toBe('Updated Name');
});
