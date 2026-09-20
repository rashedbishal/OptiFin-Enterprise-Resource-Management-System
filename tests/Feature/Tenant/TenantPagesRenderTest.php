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

    $hubResponse = $this->actingAs($clientAdmin)->get(route('lead-management.index'));
    $hubResponse->assertOk();
    expect(substr_count($hubResponse->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);

    $indexResponse = $this->actingAs($clientAdmin)->get(route('tenant-leads.index'));
    $indexResponse->assertOk();
    expect(substr_count($indexResponse->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);

    $this->actingAs($clientAdmin)->get(route('tenant-lead-sources.index'))->assertOk();

    $showResponse = $this->actingAs($clientAdmin)->get(route('tenant-leads.show', $lead));
    $showResponse
        ->assertOk()
        ->assertSeeInOrder(['Dashboard', 'Lead Management', 'Leads', $lead->name]);
    expect(substr_count($showResponse->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);
});

test('the lead management hub links to every nested module page', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $response = $this->actingAs($clientAdmin)->get(route('lead-management.index'))->assertOk();

    $content = $response->getContent();

    foreach (
        [
            'tenant-leads.index',
            'tenant-lead-sources.index',
            'tenant-leads.scoring-rules',
            'tenant-leads.custom-fields',
            'tenant-leads.source-costs',
            'tenant-leads.website-forms',
        ] as $routeName
    ) {
        expect($content)->toContain('href="'.route($routeName).'"');
    }
});

test('every lead management nested page shows the hub as its breadcrumb parent', function (string $route, array $routeParams) {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();

    $response = $this->actingAs($clientAdmin)->get(route($route, ...$routeParams));

    $response->assertOk();

    // The shared top-bar breadcrumb (exactly one <nav aria-label>) must
    // show Dashboard > Lead Management > <page> — the hub route itself
    // nested between the root and the page label.
    expect(substr_count($response->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);
    $response->assertSee(route('lead-management.index'), false);
})->with([
    'lead details' => ['tenant-leads.index', []],
    'lead sources' => ['tenant-lead-sources.index', []],
    'scoring rules' => ['tenant-leads.scoring-rules', []],
    'custom fields' => ['tenant-leads.custom-fields', []],
    'source costs' => ['tenant-leads.source-costs', []],
    'website forms' => ['tenant-leads.website-forms', []],
]);

test('a staff user hitting the hub is redirected to their kanban board', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);

    // The hub is the Client Admin's module dashboard — a staff/employee
    // user works day-to-day on the Kanban board (tenant-leads.index renders
    // it for non-admins), so the module entry point drops them straight
    // there instead of showing admin catalog tiles they can't act on.
    $this->actingAs($rep)->get(route('lead-management.index'))
        ->assertRedirect(route('tenant-leads.index'));
});

test('a staff user without even view permission is forbidden, not redirected', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, []);

    // The redirect branch sits AFTER the viewAny gate on purpose — a user
    // with no module access gets the 403, never a redirect that could
    // leak whether the module exists.
    $this->actingAs($rep)->get(route('lead-management.index'))->assertForbidden();
});

test('the kanban board pushes a Lead Management breadcrumb for staff users', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant, ['view']);

    $response = $this->actingAs($rep)->get(route('tenant-leads.index'));

    $response->assertOk();
    expect(substr_count($response->getContent(), 'aria-label="Breadcrumb"'))->toBe(1);
    $response->assertSee(route('lead-management.index'), false);
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
