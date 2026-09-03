<?php

use App\Livewire\Platform\Admins\Index as AdminsIndex;
use App\Livewire\Platform\Modules\Index as ModulesIndex;
use App\Livewire\Platform\Roles\Index as RolesIndex;
use App\Models\Module;
use App\Models\PlatformUser;
use App\Models\Role;
use Livewire\Livewire;

test('deleting a platform admin goes through the confirm modal, not an immediate delete', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $admin = PlatformUser::factory()->create();

    $component = Livewire::actingAs($motherAdmin, 'platform')
        ->test(AdminsIndex::class)
        ->call('confirmDelete', $admin->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('confirmingDeleteId', $admin->id);

    expect(PlatformUser::find($admin->id))->not->toBeNull();

    $component->call('deleteConfirmed')
        ->assertSet('showDeleteModal', false)
        ->assertDispatched('toast-show');

    expect(PlatformUser::find($admin->id))->toBeNull();
});

test('deleting a module goes through the confirm modal', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $module = Module::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(ModulesIndex::class)
        ->call('confirmDelete', $module->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteConfirmed')
        ->assertSet('showDeleteModal', false);

    expect(Module::find($module->id))->toBeNull();
});

test('deleting a platform role goes through the confirm modal', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $role = Role::factory()->create();

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(RolesIndex::class)
        ->call('confirmDelete', $role->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteConfirmed')
        ->assertSet('showDeleteModal', false);

    expect(Role::find($role->id))->toBeNull();
});
