<?php

use App\Livewire\Platform\LeadStatuses\Index;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

test('mother admin can create a custom lead status', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Index::class)
        ->set('name', 'On Hold')
        ->set('color', 'purple')
        ->set('isClosed', false)
        ->call('save')
        ->assertHasNoErrors();

    $status = LeadStatus::where('slug', 'on-hold')->firstOrFail();
    expect($status->name)->toBe('On Hold');
    expect($status->color)->toBe('purple');
    expect($status->is_closed)->toBeFalse();
});

test('mother admin can edit an existing lead status', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $status = LeadStatus::factory()->create(['name' => 'Draft', 'color' => 'zinc']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Index::class)
        ->call('edit', $status->id)
        ->set('name', 'Archived')
        ->set('color', 'red')
        ->set('isClosed', true)
        ->call('save')
        ->assertHasNoErrors();

    $status->refresh();
    expect($status->name)->toBe('Archived');
    expect($status->color)->toBe('red');
    expect($status->is_closed)->toBeTrue();
});

test('two statuses cannot share the same name', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    LeadStatus::factory()->create(['name' => 'Escalated', 'slug' => 'escalated']);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Index::class)
        ->set('name', 'Escalated')
        ->call('save');

    expect(LeadStatus::where('name', 'Escalated')->count())->toBe(1);
});

test('a status referenced by a lead cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $status = $tenant->leadStatuses()->first();
    Lead::factory()->create(['tenant_id' => $tenant->id, 'status_id' => $status->id]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Index::class)
        ->call('delete', $status->id)
        ->assertDispatched('toast-show');

    expect(LeadStatus::whereKey($status->id)->exists())->toBeTrue();
});

test('a status selected in a tenant pipeline but used by no leads cannot be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $status = $tenant->leadStatuses()->first();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Index::class)
        ->call('delete', $status->id)
        ->assertDispatched('toast-show');

    expect(LeadStatus::whereKey($status->id)->exists())->toBeTrue();
});

test('a status used by no client can be deleted', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $status = LeadStatus::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Index::class)
        ->call('delete', $status->id)
        ->assertDispatched('toast-show');

    expect(LeadStatus::whereKey($status->id)->exists())->toBeFalse();
});
