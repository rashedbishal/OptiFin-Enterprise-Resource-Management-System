<?php

namespace App\Actions\Platform;

use App\Enums\TenantUserType;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creating a Client provisions its tenant record, a protected "Client
 * Admin" owner role, and the first Client Admin user in one transaction -
 * a tenant is never left without an owner.
 */
class CreateClient
{
    /**
     * F3 remediation (forensic audit Batch C) — nextTenantCode()'s own
     * read-then-format has no lock (see that method's own docblock for why
     * a full row lock was judged heavier than this problem needs); a
     * genuine simultaneous collision is rare (a low-frequency, admin-only
     * action), so a small bounded retry is the right size of fix rather
     * than a new locking primitive. 3 matches the retry ceiling this exact
     * codebase already uses for its own bounded-retry precedent
     * (LeadAssignmentService::autoAssign()'s `attempts: 3` on a PostgreSQL
     * deadlock).
     */
    private const int MAX_TENANT_CODE_ATTEMPTS = 3;

    /**
     * @param  array<string, mixed>  $tenantData
     * @param  array<string, mixed>  $adminData
     */
    public function handle(array $tenantData, array $adminData, PlatformUser $createdBy): Tenant
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->createTenantAndOwner($tenantData, $adminData, $createdBy);
            } catch (UniqueConstraintViolationException $e) {
                // F3 remediation — only a genuine tenant_code collision is
                // retried here: Laravel's own UniqueConstraintViolationException
                // exposes exactly which column(s) the database reported as
                // the cause (both the Postgres and SQLite drivers populate
                // this reliably — verified directly against this app's own
                // installed framework source), so this never blindly
                // retries an unrelated unique-constraint failure (e.g. a
                // duplicate admin email on tenant_users, a different
                // problem this action must still surface immediately, not
                // mask behind a tenant-code-shaped retry). The transaction
                // DB::transaction() opens on each attempt already rolls
                // back the failed insert in full before this catch even
                // runs, so there is nothing further to discard here.
                if (! in_array('tenant_code', $e->columns, true) || $attempt >= self::MAX_TENANT_CODE_ATTEMPTS) {
                    throw $e;
                }

                Log::warning("CreateClient: tenant_code collision on attempt {$attempt}, retrying with a freshly computed code.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tenantData
     * @param  array<string, mixed>  $adminData
     */
    private function createTenantAndOwner(array $tenantData, array $adminData, PlatformUser $createdBy): Tenant
    {
        return DB::transaction(function () use ($tenantData, $adminData, $createdBy) {
            $tenant = Tenant::create([
                ...$tenantData,
                'tenant_code' => $this->nextTenantCode(),
                'lead_id_prefix' => Tenant::generateLeadIdPrefix($tenantData['name']),
                'created_by' => $createdBy->id,
            ]);

            $ownerRole = Role::create([
                'tenant_id' => $tenant->id,
                'name' => 'Client Admin',
                'slug' => 'client-admin',
                'is_system' => true,
                'is_owner_role' => true,
            ]);

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_type' => TenantUserType::ClientAdmin,
                'name' => $adminData['name'],
                'email' => $adminData['email'],
                'password' => $adminData['password'],
                'role_id' => $ownerRole->id,
            ]);

            return $tenant;
        });
    }

    private function nextTenantCode(): string
    {
        $last = Tenant::query()->orderByDesc('tenant_code')->value('tenant_code');
        $next = $last ? ((int) substr($last, 4)) + 1 : 1;

        return sprintf('TEN-%06d', $next);
    }
}
