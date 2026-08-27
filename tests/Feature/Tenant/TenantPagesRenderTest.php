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

test('a client admin can view the lead management pages', function () {
    ['tenant' => $tenant, 'clientAdmin' => $clientAdmin] = tenantWithLeadManagement();
    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($clientAdmin)->get(route('tenant-leads.index'))->assertOk();
    $this->actingAs($clientAdmin)->get(route('tenant-lead-sources.index'))->assertOk();
    $this->actingAs($clientAdmin)->get(route('tenant-leads.show', $lead))->assertOk();
});

test('a permitted client user can view their own lead but not the leads index for others', function () {
    ['tenant' => $tenant] = tenantWithLeadManagement();
    $rep = clientUserWithLeadPermission($tenant);
    $ownLead = Lead::factory()->create(['tenant_id' => $tenant->id, 'assigned_to' => $rep->id]);

    $this->actingAs($rep)->get(route('tenant-leads.index'))->assertOk();
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

    $this->actingAs($clientAdmin)
        ->get(route('modules.show', $module))
        ->assertOk();
});
