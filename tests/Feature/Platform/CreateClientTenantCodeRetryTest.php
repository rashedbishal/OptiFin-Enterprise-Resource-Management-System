<?php

use App\Actions\Platform\CreateClient;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * F3 remediation (forensic audit Batch C) test coverage.
 *
 * CreateClient::nextTenantCode()'s read-then-format has no row lock, so a
 * genuinely concurrent request can compute the same "next" code twice; the
 * fix retries once a real UniqueConstraintViolationException reports the
 * collision came from the tenant_code column specifically.
 *
 * CONCURRENCY TESTING RULE: tests here that inject the collision via
 * Tenant::creating() are a DETERMINISTIC, SINGLE-PROCESS simulation of that
 * race — they synthesize the exact exception shape a real concurrent INSERT
 * would produce (Laravel's SQLite/Postgres drivers both reliably populate
 * ->columns for a genuine unique-constraint violation; verified directly
 * against this app's installed framework source), so the retry LOGIC in
 * CreateClient::handle() is exercised precisely, without needing two real,
 * separately-committed connections in this single-connection sqlite test
 * run. No test in this file claims to have validated real multi-process
 * concurrency. The "unrelated column" test below is the one exception: it
 * triggers a genuine, real database-level unique violation (no synthetic
 * exception), because tenant_users.email's own unique index makes that
 * trivial to reproduce for real.
 */
function tenantCodeCollisionException(): UniqueConstraintViolationException
{
    return (new UniqueConstraintViolationException(
        'sqlite',
        'insert into "tenants" ("tenant_code", ...) values (...)',
        [],
        new Exception('UNIQUE constraint failed: tenants.tenant_code'),
    ))->setColumns(['tenant_code'])->setIndex('tenants_tenant_code_unique');
}

test('a client is created normally with a well-formed tenant_code when there is no collision', function () {
    $platformAdmin = PlatformUser::factory()->create();

    $tenant = app(CreateClient::class)->handle(
        tenantData: ['name' => 'Acme Co'],
        adminData: ['name' => 'Owner', 'email' => 'owner@acme.test', 'password' => 'password'],
        createdBy: $platformAdmin,
    );

    expect($tenant->tenant_code)->toMatch('/^TEN-\d{6}$/');
    expect(Tenant::count())->toBe(1);
    expect(TenantUser::where('email', 'owner@acme.test')->exists())->toBeTrue();
});

test('a genuine tenant_code collision is retried once and the client is still created successfully', function () {
    $platformAdmin = PlatformUser::factory()->create();

    $attempts = 0;

    Tenant::creating(function () use (&$attempts): void {
        $attempts++;

        if ($attempts === 1) {
            throw tenantCodeCollisionException();
        }
    });

    Log::shouldReceive('warning')->once()->with(Mockery::pattern('/tenant_code collision on attempt 1/'));

    $tenant = app(CreateClient::class)->handle(
        tenantData: ['name' => 'Acme Co'],
        adminData: ['name' => 'Owner', 'email' => 'owner@acme.test', 'password' => 'password'],
        createdBy: $platformAdmin,
    );

    expect($attempts)->toBe(2);
    expect($tenant->tenant_code)->toMatch('/^TEN-\d{6}$/');
    expect(Tenant::count())->toBe(1);
    expect(TenantUser::where('email', 'owner@acme.test')->exists())->toBeTrue();
});

test('repeated tenant_code collisions are bounded: the action gives up after 3 attempts and leaves no partial data', function () {
    $platformAdmin = PlatformUser::factory()->create();

    $attempts = 0;

    Tenant::creating(function () use (&$attempts): void {
        $attempts++;

        throw tenantCodeCollisionException();
    });

    // Only attempts 1 and 2 log a retry warning — attempt 3 hits the
    // MAX_TENANT_CODE_ATTEMPTS ceiling and rethrows immediately instead,
    // per CreateClient::handle()'s own `$attempt >= self::MAX_TENANT_CODE_ATTEMPTS`
    // guard (checked before the log call, not after).
    Log::shouldReceive('warning')->twice();

    expect(fn () => app(CreateClient::class)->handle(
        tenantData: ['name' => 'Acme Co'],
        adminData: ['name' => 'Owner', 'email' => 'owner@acme.test', 'password' => 'password'],
        createdBy: $platformAdmin,
    ))->toThrow(UniqueConstraintViolationException::class);

    expect($attempts)->toBe(3);
    expect(Tenant::count())->toBe(0);
    expect(TenantUser::count())->toBe(0);
});

test('a unique constraint violation on an unrelated column is surfaced immediately, never retried as a tenant_code collision', function () {
    $platformAdmin = PlatformUser::factory()->create();

    $existingTenant = Tenant::factory()->create();
    TenantUser::factory()->create(['tenant_id' => $existingTenant->id, 'email' => 'owner@acme.test']);

    Log::shouldReceive('warning')->never();

    expect(fn () => app(CreateClient::class)->handle(
        tenantData: ['name' => 'New Co'],
        adminData: ['name' => 'Owner', 'email' => 'owner@acme.test', 'password' => 'password'],
        createdBy: $platformAdmin,
    ))->toThrow(UniqueConstraintViolationException::class);

    // The whole transaction — including the new Tenant this action had
    // already successfully inserted before the downstream TenantUser email
    // collision occurred — rolled back. F3's retry guard never partially
    // commits an unrelated failure.
    expect(Tenant::where('name', 'New Co')->exists())->toBeFalse();
});
