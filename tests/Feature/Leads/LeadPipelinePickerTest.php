<?php

use App\Livewire\Platform\Clients\LeadSources;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

test('removing a selected status asks for confirmation but adding an unselected one does not', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $custom = LeadStatus::factory()->create(['name' => 'Custom Stage']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->assertSeeHtml('wire:confirm="Remove New from this client&#039;s pipeline?"')
        ->assertDontSeeHtml('wire:confirm="Remove Custom Stage');
});

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

test('a status with leads currently in it cannot be removed from the pipeline', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('qualified')->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('toggleLeadStatus', leadStatus('qualified')->id)
        ->assertDispatched('toast-show');

    expect($tenant->fresh()->leadStatuses->pluck('slug')->all())
        ->toBe(['new', 'contacted', 'qualified', 'proposal_sent', 'negotiation', 'won', 'lost', 'unqualified']);
});

test('a status with no leads in it can still be removed even if other statuses have leads', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('qualified')->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('toggleLeadStatus', leadStatus('negotiation')->id);

    expect($tenant->fresh()->leadStatuses->pluck('slug')->all())
        ->toBe(['new', 'contacted', 'qualified', 'proposal_sent', 'won', 'lost', 'unqualified']);
});

test('a new status can still be added to the pipeline even while other statuses have leads', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('qualified')->id]);
    $custom = LeadStatus::factory()->create(['name' => 'Custom Stage']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(LeadSources::class, ['tenant' => $tenant])
        ->call('toggleLeadStatus', $custom->id);

    expect($tenant->fresh()->leadStatuses->last()->id)->toBe($custom->id);
});
