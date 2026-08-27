<?php

use App\Actions\Platform\CreateClient;
use App\Models\PlatformUser;
use App\Models\Tenant;

function createTestClient(): Tenant
{
    return app(CreateClient::class)->handle(
        tenantData: ['name' => 'Acme Inc.'],
        adminData: ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password'],
        createdBy: PlatformUser::factory()->motherAdmin()->create(),
    );
}

test('the last client admin of a tenant cannot be deleted', function () {
    $tenant = createTestClient();
    $owner = $tenant->tenantUsers()->first();

    expect($owner->delete())->toBeFalse();
    expect($tenant->tenantUsers()->whereKey($owner->id)->exists())->toBeTrue();
});

test('the last client admin of a tenant cannot be deactivated', function () {
    $tenant = createTestClient();
    $owner = $tenant->tenantUsers()->first();

    $owner->update(['is_active' => false]);

    expect($owner->fresh()->is_active)->toBeTrue();
});

test('the tenant owner role cannot be deleted', function () {
    $tenant = createTestClient();
    $ownerRole = $tenant->ownerRole();

    expect($ownerRole->delete())->toBeFalse();
});
