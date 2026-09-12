<?php

use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use App\Models\LeadTag;
use Livewire\Livewire;

test('the single-select tag filter narrows the table to leads carrying that tag', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);
    $tagged = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);
    $untagged = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);
    $tagged->tags()->attach($tag->id);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->call('completeInitialLoad');

    expect($component->get('leads')->pluck('id'))->toContain($tagged->id)->toContain($untagged->id);

    $component->set('tag', $tag->id);

    expect($component->get('leads')->pluck('id'))->toContain($tagged->id)->not->toContain($untagged->id);
});

test('the single-select tag filter also narrows a client user\'s Kanban board', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $tagged = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'status_id' => leadStatus('new')->id]);
    $untagged = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'status_id' => leadStatus('new')->id]);
    $tagged->tags()->attach($tag->id);

    $component = Livewire::actingAs($rep)->test(Index::class)->call('completeInitialLoad')->set('tag', $tag->id);

    $boardIds = $component->get('boardLeads')->collapse()->pluck('id');
    expect($boardIds)->toContain($tagged->id)->not->toContain($untagged->id);
});

test('an unrecognized tag filter value simply matches nothing, never another tenant\'s leads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    $foreignTag = LeadTag::factory()->create(['tenant_id' => $otherTenant->id]);
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->set('tag', $foreignTag->id);

    expect($component->get('leads'))->toHaveCount(0);
});

test('the leads table shows up to 2 tag chips inline and caps the rest behind a "+N" badge', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tags = LeadTag::factory()->count(3)->sequence(
        ['name' => 'Alpha'],
        ['name' => 'Beta'],
        ['name' => 'Gamma'],
    )->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);
    $lead->tags()->attach($tags->pluck('id'));

    $component = Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->assertSee('Alpha')
        ->assertSee('Beta')
        ->assertSee('+1');

    // "Gamma" legitimately appears once regardless of capping — it's one
    // of the Tags filter dropdown's own options (embedded as JSON in that
    // component's x-data attribute, see resources/views/components/
    // dropdown-select.blade.php) — but it must NOT also appear a second
    // time as an actual rendered chip in the table row, which is what
    // capping to 2 is supposed to prevent.
    expect(substr_count($component->html(), 'Gamma'))->toBe(1);
});

test('a lead with no tags shows a dash in the table\'s Tags column instead of an empty cell', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => leadStatus('new')->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->assertSee('—');
});

test('the Kanban card shows a lead\'s tag chips, capped the same way as the table', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $tags = LeadTag::factory()->count(3)->sequence(
        ['name' => 'Alpha'],
        ['name' => 'Beta'],
        ['name' => 'Gamma'],
    )->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id, 'status_id' => leadStatus('new')->id]);
    $lead->tags()->attach($tags->pluck('id'));

    $component = Livewire::actingAs($rep)
        ->test(Index::class)
        ->call('completeInitialLoad')
        ->assertSee('Alpha')
        ->assertSee('Beta')
        ->assertSee('+1');

    // Same reasoning as the table test above: "Gamma" legitimately appears
    // once, as a Tags filter dropdown option, never as a second, actual
    // rendered chip on the card.
    expect(substr_count($component->html(), 'Gamma'))->toBe(1);
});
