<?php

use App\Livewire\Platform\Clients\LeadSources;
use App\Models\LeadStatus;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

test('a freshly created tenant has the full catalog selected in catalog order', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    $component = Livewire::actingAs($motherAdmin, 'platform')->test(LeadSources::class, ['tenant' => $tenant]);

    expect($component->get('selectedLeadStatuses')->pluck('slug')->all())
        ->toBe(['new', 'contacted', 'qualified', 'proposal_sent', 'negotiation', 'won', 'lost', 'unqualified']);
});

test('clicking a selected status removes it and renumbers the rest', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('toggleLeadStatus', leadStatus('contacted')->id);

    expect($tenant->fresh()->leadStatuses->pluck('slug')->all())
        ->toBe(['new', 'qualified', 'proposal_sent', 'negotiation', 'won', 'lost', 'unqualified']);

    // Renumbered contiguously, not left with a gap where "contacted" was.
    expect($tenant->fresh()->leadStatuses()->orderByPivot('position')->pluck('position')->all())
        ->toBe([1, 2, 3, 4, 5, 6, 7]);
});

test('clicking an unselected status appends it at the next position', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $custom = LeadStatus::factory()->create(['name' => 'Custom Stage']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('toggleLeadStatus', $custom->id);

    $pipeline = $tenant->fresh()->leadStatuses;
    expect($pipeline->last()->id)->toBe($custom->id);
    expect($pipeline)->toHaveCount(9);
});

test('the last remaining pipeline status cannot be removed', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    // Strip down to a single status first.
    foreach (['contacted', 'qualified', 'proposal_sent', 'negotiation', 'won', 'lost', 'unqualified'] as $slug) {
        $tenant->leadStatuses()->detach(leadStatus($slug)->id);
    }

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('toggleLeadStatus', leadStatus('new')->id)
        ->assertDispatched('toast-show');

    expect($tenant->fresh()->leadStatuses->pluck('slug')->all())->toBe(['new']);
});
