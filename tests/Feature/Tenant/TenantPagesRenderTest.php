<?php

use App\Actions\Platform\AssignModuleToTenant;
use App\Actions\Platform\CreateClient;
use App\Enums\ModuleScope;
use App\Models\Lead;
use App\Models\Module;
use App\Models\PlatformUser;
use Database\Seeders\PermissionSeeder;

test('a client admin can view the tenant pages', function (string $route) {
    $tenant = app(CreateClient::class)->handle(
        tenantData: ['name' => 'Acme Inc.'],
        adminData: ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password'],
        createdBy: PlatformUser::factory()->motherAdmin()->create(),
    );
    $clientAdmin = $tenant->tenantUsers()->first();

    $this->actingAs($clientAdmin)
        ->get(route($route))
        ->assertOk();
})->with([
    'dashboard',
    'tenant-users.index',
    'tenant-roles.index',
]);

test('every tenant page pushes its breadcrumb into the shared top bar exactly once', function (string $route) {
    $tenant = app(CreateClient::class)->handle(
        tenantData: ['name' => 'Acme Inc.'],
        adminData: ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password'],
        createdBy: PlatformUser::factory()->motherAdmin()->create(),
    );
    $clientAdmin = $tenant->tenantUsers()->first();

    $response = $this->actingAs($clientAdmin)->get(route($route));

    $response->assertOk();

    // <x-breadcrumbs> renders a <nav aria-label="Breadcrumb">. Exactly one
    // occurrence proves @push('breadcrumbs') in the page's own view
    // actually reached @stack('breadcrumbs') in layouts/app.blade.php's
    // shared bar (zero would mean it silently vanished; more than one
    // would mean it's still also rendering inline where it used to).
    expect(substr_count($response->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);
})->with([
    'dashboard',
    'tenant-users.index',
    'tenant-roles.index',
    // security.edit isn't included here: it requires a freshly-confirmed
    // password (password.confirm middleware) that this test doesn't set
    // up, unrelated to what's being checked here. It shares the exact same
    // partials.settings-heading breadcrumb as profile.edit, so that
    // coverage already proves the partial itself works correctly.
    'profile.edit',
]);

test('a client admin can view the lead management pages', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $indexResponse = $this->actingAs($clientAdmin)->get(route('tenant-leads.index'));
    $indexResponse->assertOk();
    expect(substr_count($indexResponse->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);

    $this->actingAs($clientAdmin)->get(route('tenant-lead-sources.index'))->assertOk();

    $showResponse = $this->actingAs($clientAdmin)->get(route('tenant-leads.show', $lead));
    $showResponse
        ->assertOk()
        ->assertSeeInOrder(['Dashboard', 'Leads', $lead->name]);
    expect(substr_count($showResponse->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);
});

test('a permitted client user can view their own lead but not the leads index for others', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $ownLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    $kanbanResponse = $this->actingAs($rep)->get(route('tenant-leads.index'));
    $kanbanResponse->assertOk();
    expect(substr_count($kanbanResponse->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);

    $this->actingAs($rep)->get(route('tenant-leads.show', $ownLead))->assertOk();
});

test('a client user can view an assigned module placeholder page', function () {
    $this->seed(PermissionSeeder::class);

    $tenant = app(CreateClient::class)->handle(
        tenantData: ['name' => 'Acme Inc.'],
        adminData: ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password'],
        createdBy: $platformAdmin = PlatformUser::factory()->motherAdmin()->create(),
    );
    $module = Module::factory()->create(['scope' => ModuleScope::Tenant]);
    app(AssignModuleToTenant::class)->handle($tenant, $module, $platformAdmin);

    $clientAdmin = $tenant->tenantUsers()->first();

    $response = $this->actingAs($clientAdmin)->get(route('modules.show', $module));

    $response->assertOk();
    expect(substr_count($response->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);
});
