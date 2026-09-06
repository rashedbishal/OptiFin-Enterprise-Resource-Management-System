<?php

use App\Livewire\Tenant\Leads\Index;
use App\Models\Lead;
use Livewire\Livewire;

test('searching by name narrows the table to matching leads only', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $match = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Jane Doe']);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'John Smith']);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->set('search', 'Jane');

    $ids = collect($component->get('leads')->items())->pluck('id');
    expect($ids)->toContain($match->id);
    expect($ids)->not->toContain($other->id);
});

test('a literal underscore in the search term is not treated as a SQL LIKE single-character wildcard', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    // Without escaping, "_" is a LIKE wildcard matching any one character —
    // "batch_42" would then also match "batchX42", which is exactly what
    // this test must rule out.
    $match = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'Batch_42 Ltd']);
    $other = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'BatchX42 Ltd']);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->set('search', 'batch_42');

    $ids = collect($component->get('leads')->items())->pluck('id');
    expect($ids)->toContain($match->id);
    expect($ids)->not->toContain($other->id);
});

test('search matches Lead ID, phone, email, company, and location', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $byIdentifier = Lead::factory()->create(['tenant_id' => $tenant->id]);
    $byPhone = Lead::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+1-555-0199']);
    $byEmail = Lead::factory()->create(['tenant_id' => $tenant->id, 'email' => 'unique-match@example.com']);
    $byCompany = Lead::factory()->create(['tenant_id' => $tenant->id, 'company' => 'Acme Rockets']);
    $byLocation = Lead::factory()->create(['tenant_id' => $tenant->id, 'location' => 'Dhaka, Bangladesh']);

    $this->actingAs($clientAdmin);

    expect(Livewire::test(Index::class)->set('search', $byIdentifier->identifier)->get('leads')->pluck('id'))->toContain($byIdentifier->id);
    expect(Livewire::test(Index::class)->set('search', '555-0199')->get('leads')->pluck('id'))->toContain($byPhone->id);
    expect(Livewire::test(Index::class)->set('search', 'unique-match')->get('leads')->pluck('id'))->toContain($byEmail->id);
    expect(Livewire::test(Index::class)->set('search', 'Acme Rockets')->get('leads')->pluck('id'))->toContain($byCompany->id);
    expect(Livewire::test(Index::class)->set('search', 'Dhaka')->get('leads')->pluck('id'))->toContain($byLocation->id);
});

test('search matches win probability and deal value even though they are numeric columns', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id, 'win_probability' => 77, 'deal_value' => '4321.00']);
    $this->actingAs($clientAdmin);

    expect(Livewire::test(Index::class)->set('search', '77')->get('leads')->pluck('id'))->toContain($lead->id);
    expect(Livewire::test(Index::class)->set('search', '4321')->get('leads')->pluck('id'))->toContain($lead->id);
});

test('an empty search shows every lead again', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    Lead::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->set('search', 'nonexistent-term');
    expect($component->get('leads')->total())->toBe(0);

    $component->set('search', '');
    expect($component->get('leads')->total())->toBe(3);
});

test('search is case-insensitive', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $match = Lead::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Jane Doe', 'company' => 'Acme Rockets']);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->set('search', 'JANE doe');
    expect($component->get('leads')->pluck('id'))->toContain($match->id);

    $component->set('search', 'acme ROCKETS');
    expect($component->get('leads')->pluck('id'))->toContain($match->id);
});

test('search cannot leak another tenant\'s leads', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    ['tenant' => $otherTenant] = tenantWithLeadManagement();
    Lead::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Cross Tenant Lead']);

    $component = Livewire::actingAs($clientAdmin)->test(Index::class)->set('search', 'Cross Tenant');

    expect($component->get('leads')->total())->toBe(0);
});
