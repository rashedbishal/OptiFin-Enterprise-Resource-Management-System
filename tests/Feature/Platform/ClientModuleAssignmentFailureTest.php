<?php

use App\Actions\Platform\AssignModuleToTenant;
use App\Enums\ModuleScope;
use App\Livewire\Platform\Clients\Show;
use App\Models\Module;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

test('a database failure while assigning a module shows a toast instead of a fatal error', function () {
    $motherAdmin = PlatformUser::factory()->motherAdmin()->create();
    $tenant = Tenant::factory()->create();
    $module = Module::factory()->create(['scope' => ModuleScope::Tenant]);

    $this->mock(AssignModuleToTenant::class)
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new QueryException('pgsql', 'select 1', [], new Exception('relation "lead_statuses" does not exist')));

    Livewire::actingAs($motherAdmin, 'platform')
        ->test(Show::class, ['tenant' => $tenant])
        ->call('toggleModule', $module->id)
        ->assertDispatched('toast-show', fn ($name, $params) => $params['dataset']['variant'] === 'danger');
});
