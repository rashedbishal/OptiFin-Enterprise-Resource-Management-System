<?php

use App\Livewire\Tenant\LeadTags\Index;
use App\Models\Lead;
use App\Models\LeadTag;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('a client admin can create a tag', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'VIP')
        ->set('color', 'purple')
        ->call('save')
        ->assertHasNoErrors();

    $tag = LeadTag::where('tenant_id', $tenant->id)->firstOrFail();
    expect($tag->name)->toBe('VIP');
    expect($tag->slug)->toBe('vip');
    expect($tag->color)->toBe('purple');
});

test('a client admin can rename and recolor a tag', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Original', 'slug' => 'original', 'color' => 'blue']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('edit', $tag->id)
        ->set('name', 'Renamed')
        ->set('color', 'red')
        ->call('save')
        ->assertHasNoErrors();

    expect($tag->fresh()->name)->toBe('Renamed');
    expect($tag->fresh()->slug)->toBe('renamed');
    expect($tag->fresh()->color)->toBe('red');
});

test('a client admin can delete a tag', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $tag->id)
        ->call('deleteConfirmed');

    expect(LeadTag::find($tag->id))->toBeNull();
});

test('a tag name must be unique within a tenant', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'VIP', 'slug' => 'vip']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'VIP')
        ->set('color', 'blue')
        ->call('save')
        ->assertHasErrors('name');

    expect(LeadTag::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('a tag name colliding only after slugification is still rejected', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'VIP', 'slug' => 'vip']);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'vip')
        ->set('color', 'blue')
        ->call('save')
        ->assertHasErrors('name');

    expect(LeadTag::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('the same tag name/slug is allowed independently across different tenants', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB, 'clientAdmin' => $adminB] = tenantWithLeadManagement();

    Livewire::actingAs($adminA)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'VIP')
        ->set('color', 'blue')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($adminB)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'VIP')
        ->set('color', 'green')
        ->call('save')
        ->assertHasNoErrors();

    expect(LeadTag::where('tenant_id', $tenantA->id)->where('slug', 'vip')->exists())->toBeTrue();
    expect(LeadTag::where('tenant_id', $tenantB->id)->where('slug', 'vip')->exists())->toBeTrue();
});

test('a client user without admin rights cannot access the tag catalog page', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view', 'update']);

    Livewire::actingAs($rep)
        ->test(Index::class)
        ->assertForbidden();
});

test('a tenant\'s tag catalog never appears for, or can be touched by, another tenant', function () {
    ['tenant' => $tenantA, 'clientAdmin' => $adminA] = tenantWithLeadManagement();
    ['tenant' => $tenantB] = tenantWithLeadManagement();

    $tagA = LeadTag::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A Tag', 'slug' => 'tenant-a-tag']);
    LeadTag::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B Tag', 'slug' => 'tenant-b-tag']);

    $component = Livewire::actingAs($adminA)->test(Index::class);

    $component->assertSee('Tenant A Tag')->assertDontSee('Tenant B Tag');

    expect(fn () => $component->call('edit', LeadTag::where('tenant_id', $tenantB->id)->firstOrFail()->id))
        ->toThrow(ModelNotFoundException::class);

    $tenantBTagId = LeadTag::where('tenant_id', $tenantB->id)->firstOrFail()->id;

    // delete() re-scopes by tenant_id via findOrFail() too, same as edit()
    // — a cross-tenant id must never actually delete the other tenant's
    // row, so this is expected to throw rather than silently no-op.
    expect(fn () => $component->call('confirmDelete', $tenantBTagId)->call('deleteConfirmed'))
        ->toThrow(ModelNotFoundException::class);

    expect($tagA->fresh())->not->toBeNull();
    expect(LeadTag::where('tenant_id', $tenantB->id)->count())->toBe(1);
});

test('the catalog shows how many leads currently use each tag', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);
    $leads = Lead::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    foreach ($leads as $lead) {
        $lead->tags()->attach($tag->id);
    }

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->assertSee('3 leads');
});

test('deleting a tag detaches it from every lead that carried it', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $otherTag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $lead->tags()->attach([$tag->id, $otherTag->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $tag->id)
        ->assertSet('confirmingDeleteUsageCount', 1)
        ->call('deleteConfirmed');

    expect(LeadTag::find($tag->id))->toBeNull();
    $lead->refresh();
    expect($lead->tags->pluck('id')->all())->toBe([$otherTag->id]);
});

test('the delete confirmation usage count reflects zero when a tag is unused', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $tag = LeadTag::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::actingAs($clientAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $tag->id)
        ->assertSet('confirmingDeleteUsageCount', 0);
});
