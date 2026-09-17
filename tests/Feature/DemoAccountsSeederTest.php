<?php

use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Hash;

covers(DemoAccountsSeeder::class);

it('seeds both demo accounts with known credentials', function () {
    (new PermissionSeeder)->run();

    (new DemoAccountsSeeder)->run();

    $systemAdmin = PlatformUser::query()->where('email', 'admin@erp.com')->first();
    $clientAdmin = TenantUser::query()->where('email', 'clientadmin@erp.com')->first();

    expect($systemAdmin)->toBeInstanceOf(PlatformUser::class)
        ->and($systemAdmin->user_type->value)->toBe('platform_admin')
        ->and($systemAdmin->is_active)->toBeTrue()
        ->and(Hash::check('password', $systemAdmin->password))->toBeTrue();

    expect($clientAdmin)->toBeInstanceOf(TenantUser::class)
        ->and($clientAdmin->user_type->value)->toBe('client_admin')
        ->and($clientAdmin->is_active)->toBeTrue()
        ->and($clientAdmin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $clientAdmin->password))->toBeTrue();

    $systemRole = Role::query()->whereNull('tenant_id')->where('slug', 'system-admin')->first();
    expect($systemRole)->toBeInstanceOf(Role::class)
        ->and($systemAdmin->role_id)->toBe($systemRole->id);

    $tenant = Tenant::query()->where('name', 'ERP Demo Client')->first();
    expect($tenant)->toBeInstanceOf(Tenant::class)
        ->and($clientAdmin->tenant_id)->toBe($tenant->id);

    $ownerRole = Role::query()->where('tenant_id', $tenant->id)->where('slug', 'client-admin')->first();
    expect($ownerRole)->toBeInstanceOf(Role::class)
        ->and($ownerRole->is_owner_role)->toBeTrue()
        ->and($clientAdmin->role_id)->toBe($ownerRole->id);
});

it('is idempotent when re-run', function () {
    (new PermissionSeeder)->run();
    (new DemoAccountsSeeder)->run();
    (new DemoAccountsSeeder)->run();

    expect(PlatformUser::query()->where('email', 'admin@erp.com')->count())->toBe(1)
        ->and(TenantUser::query()->where('email', 'clientadmin@erp.com')->count())->toBe(1)
        ->and(Tenant::query()->where('name', 'ERP Demo Client')->count())->toBe(1)
        ->and(Role::query()->whereNull('tenant_id')->where('slug', 'system-admin')->count())->toBe(1);
});
