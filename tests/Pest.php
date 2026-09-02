<?php

use App\Actions\Platform\AssignModuleToTenant;
use App\Actions\Platform\CreateClient;
use App\Enums\ModuleScope;
use App\Models\LeadStatus;
use App\Models\Module;
use App\Models\Permission;
use App\Models\PlatformUser;
use App\Models\Role;
use App\Models\RoleModulePermission;
use App\Models\Tenant;
use App\Models\TenantUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a tenant with the "Lead Management" module assigned and active,
 * returning it together with its (auto-created) Client Admin.
 *
 * @return array{tenant: Tenant, clientAdmin: TenantUser}
 */
function tenantWithLeadManagement(): array
{
    (new PermissionSeeder)->run();

    $platformAdmin = PlatformUser::factory()->create();

    $tenant = app(CreateClient::class)->handle(
        tenantData: ['name' => fake()->company()],
        adminData: ['name' => 'Owner', 'email' => fake()->unique()->safeEmail(), 'password' => 'password'],
        createdBy: $platformAdmin,
    );

    $module = Module::firstOrCreate(
        ['slug' => 'lead-management'],
        ['name' => 'Lead Management', 'scope' => ModuleScope::Tenant, 'sort_order' => 0],
    );

    app(AssignModuleToTenant::class)->handle($tenant, $module, $platformAdmin);

    return ['tenant' => $tenant->fresh(), 'clientAdmin' => $tenant->tenantUsers()->first()];
}

/**
 * Create a client_user in the given tenant whose role grants the given
 * Lead Management permission(s) (defaults to "view").
 *
 * @param  array<int, string>  $permissions
 */
function clientUserWithLeadPermission(Tenant $tenant, array $permissions = ['view']): TenantUser
{
    $module = Module::where('slug', 'lead-management')->firstOrFail();

    $role = Role::create([
        'tenant_id' => $tenant->id,
        'name' => 'Sales Rep '.Str::random(6),
        'slug' => 'sales-rep-'.Str::random(6),
    ]);

    $role->modules()->attach($module->id);

    RoleModulePermission::query()->insert(
        Permission::whereIn('slug', $permissions)->pluck('id')->map(fn (string $permissionId) => [
            'role_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $permissionId,
        ])->all()
    );

    return TenantUser::factory()->create([
        'tenant_id' => $tenant->id,
        'role_id' => $role->id,
    ]);
}

/**
 * Looks up one of the 8 legacy statuses the migration seeds into the global
 * LeadStatus catalog (and every tenant's pipeline, via
 * AssignModuleToTenant/TenantFactory) by its stable slug — 'new',
 * 'contacted', 'qualified', 'proposal_sent', 'negotiation', 'won', 'lost',
 * 'unqualified'.
 */
function leadStatus(string $slug): LeadStatus
{
    return LeadStatus::where('slug', $slug)->firstOrFail();
}
