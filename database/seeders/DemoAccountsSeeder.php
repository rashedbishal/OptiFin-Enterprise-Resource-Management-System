<?php

namespace Database\Seeders;

use App\Actions\Platform\CreateClient;
use App\Enums\PlatformUserType;
use App\Enums\TenantUserType;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Database\Seeder;

/**
 * Seeds the two local demo accounts so they exist after a fresh
 * migrate + seed:
 *
 *   - System Admin   admin@erp.com       password   (login: /admin/login)
 *   - Client Admin   clientadmin@erp.com password   (login: /login)
 *
 * Re-running this seeder is safe: it never creates duplicates, and it
 * (re)applies the known demo password on every run. The demo tenant is
 * provisioned through the app's own CreateClient action so its tenant
 * code, lead ID prefix and protected owner role are exactly what the
 * platform UI would have produced.
 */
class DemoAccountsSeeder extends Seeder
{
    private const PLATFORM_ADMIN_EMAIL = 'admin@erp.com';

    private const CLIENT_ADMIN_EMAIL = 'clientadmin@erp.com';

    private const DEMO_TENANT_NAME = 'ERP Demo Client';

    private const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $systemAdmin = $this->seedSystemAdmin();

        $this->seedDemoClient($systemAdmin);
    }

    private function seedSystemAdmin(): PlatformUser
    {
        $systemRole = Role::query()->firstOrCreate(
            ['tenant_id' => null, 'slug' => 'system-admin'],
            ['name' => 'System Admin'],
        );

        $admin = PlatformUser::query()->firstOrNew(['email' => self::PLATFORM_ADMIN_EMAIL]);
        $admin->fill([
            'name' => 'System Admin',
            'user_type' => PlatformUserType::PlatformAdmin,
            'role_id' => $systemRole->id,
            'is_active' => true,
            'password' => self::DEMO_PASSWORD,
        ])->save();

        return $admin;
    }

    private function seedDemoClient(PlatformUser $createdBy): void
    {
        $tenant = Tenant::query()->where('name', self::DEMO_TENANT_NAME)->first();

        if (! $tenant instanceof Tenant) {
            $tenant = app(CreateClient::class)->handle(
                tenantData: ['name' => self::DEMO_TENANT_NAME],
                adminData: ['name' => 'Client Admin', 'email' => self::CLIENT_ADMIN_EMAIL, 'password' => self::DEMO_PASSWORD],
                createdBy: $createdBy,
            );
        }

        $clientAdmin = TenantUser::query()->firstOrNew(['email' => self::CLIENT_ADMIN_EMAIL]);
        // forceFill because email_verified_at is deliberately not fillable
        // on the model (users can't self-assign it through mass assignment).
        $clientAdmin->forceFill([
            'tenant_id' => $tenant->id,
            'name' => 'Client Admin',
            'user_type' => TenantUserType::ClientAdmin,
            'role_id' => $clientAdmin->role_id ?? $tenant->ownerRole()?->id,
            'is_active' => true,
            // The tenant routes require the "verified" middleware; a demo
            // account has no inbox to click the verification link from.
            'email_verified_at' => $clientAdmin->email_verified_at ?? now(),
            'password' => self::DEMO_PASSWORD,
        ])->save();
    }
}
