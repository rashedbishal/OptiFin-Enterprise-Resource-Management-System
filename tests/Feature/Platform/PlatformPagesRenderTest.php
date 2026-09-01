<?php

use App\Actions\Platform\AssignModuleToTenant;
use App\Enums\ModuleScope;
use App\Livewire\Platform\Clients\Show;
use App\Models\Module;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Livewire;

test('mother admin can view the platform pages', function (string $route) {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();

    $this->actingAs($motherAdmin, 'platform')
        ->get(route($route))
        ->assertOk();
})->with([
    'admin.dashboard',
    'admin.admins.index',
    'admin.roles.index',
    'admin.clients.index',
    'admin.modules.index',
    'admin.settings.password',
]);

test('mother admin can view a client\'s details page', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    $this->actingAs($motherAdmin, 'platform')
        ->get(route('admin.clients.show', $tenant))
        ->assertOk()
        ->assertSeeInOrder(['Dashboard', 'Clients', $tenant->name]);
});

test('mother admin can view a client\'s lead sources page', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();

    $this->actingAs($motherAdmin, 'platform')
        ->get(route('admin.clients.lead-sources', $tenant))
        ->assertOk();
});

test('only an assigned module\'s configure button is enabled', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $module = Module::firstOrCreate(
        ['slug' => 'lead-management'],
        ['name' => 'Lead Management', 'scope' => ModuleScope::Tenant, 'sort_order' => 0],
    );

    $component = Livewire::actingAs($motherAdmin, 'platform')->test(Show::class, ['tenant' => $tenant]);
    expect($component->instance()->isModuleAssigned($module))->toBeFalse();
    expect($component->instance()->moduleConfigUrl($module))->toBe(route('admin.clients.lead-sources', $tenant));

    app(AssignModuleToTenant::class)->handle($tenant, $module, $motherAdmin);

    $component = Livewire::actingAs($motherAdmin, 'platform')->test(Show::class, ['tenant' => $tenant]);
    expect($component->instance()->isModuleAssigned($module))->toBeTrue();
});

test('a module without a configuration page yet has no config url', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $module = Module::factory()->create(['scope' => ModuleScope::Tenant]);
    app(AssignModuleToTenant::class)->handle($tenant, $module, $motherAdmin);

    $component = Livewire::actingAs($motherAdmin, 'platform')->test(Show::class, ['tenant' => $tenant]);

    expect($component->instance()->isModuleAssigned($module))->toBeTrue();
    expect($component->instance()->moduleConfigUrl($module))->toBeNull();

    $component->call('moduleNotConfigurable')->assertHasNoErrors();
});
