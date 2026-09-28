<?php

use App\Enums\TenantStatus;
use App\Livewire\Platform\Clients\Show;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

/*
 * Batch 5 — confirmed crash-path fix (Finding #3). Show::updateStatus()
 * called TenantStatus::from($this->status) directly on a plain
 * wire:model-bound string, guarded only by an authorization Gate check
 * (who may act, not what value is safe) — so a tampered Livewire request
 * carrying a non-enum value threw an uncaught ValueError (HTTP 500).
 */

test('an invalid status value does not throw, leaves the tenant unchanged, and shows an error toast', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->set('status', 'not-a-real-status')
        ->call('updateStatus')
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger');

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

test('a valid status transition still behaves exactly as before', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->set('status', TenantStatus::Suspended->value)
        ->call('updateStatus')
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'success');

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);
});
