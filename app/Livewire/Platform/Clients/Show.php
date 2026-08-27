<?php

namespace App\Livewire\Platform\Clients;

use App\Actions\Platform\AssignModuleToTenant;
use App\Actions\Platform\RemoveModuleFromTenant;
use App\Enums\ModuleScope;
use App\Enums\TenantStatus;
use App\Models\Module;
use App\Models\Tenant;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Client Details')]
#[Layout('layouts.admin')]
class Show extends Component
{
    /**
     * Maps a module slug to the named route where it is configured for a
     * client. Modules with no entry here have no configuration page yet.
     *
     * @var array<string, string>
     */
    private const MODULE_CONFIG_ROUTES = [
        'lead-management' => 'admin.clients.lead-sources',
    ];

    public Tenant $tenant;

    public string $status;

    public function mount(Tenant $tenant): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('view', $tenant);

        $this->tenant = $tenant;
        $this->status = $tenant->status->value;
    }

    #[Computed]
    public function modules()
    {
        return Module::query()->where('scope', ModuleScope::Tenant)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function assignedModuleIds()
    {
        return $this->tenant->activeModules()->pluck('modules.id')->all();
    }

    public function toggleModule(string $moduleId): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        $module = Module::findOrFail($moduleId);

        if (in_array($moduleId, $this->assignedModuleIds, true)) {
            app(RemoveModuleFromTenant::class)->handle($this->tenant, $module);
        } else {
            app(AssignModuleToTenant::class)->handle($this->tenant, $module, Auth::guard('platform')->user());
        }

        unset($this->assignedModuleIds);
        Flux::toast(variant: 'success', text: __('Modules updated.'));
    }

    public function updateStatus(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        $this->tenant->update(['status' => TenantStatus::from($this->status)]);
        Flux::toast(variant: 'success', text: __('Client status updated.'));
    }

    public function isModuleAssigned(Module $module): bool
    {
        return in_array($module->id, $this->assignedModuleIds, true);
    }

    /**
     * The URL to configure the given module for this client, or null if it
     * has no configuration page yet.
     */
    public function moduleConfigUrl(Module $module): ?string
    {
        $routeName = self::MODULE_CONFIG_ROUTES[$module->slug] ?? null;

        return $routeName ? route($routeName, $this->tenant) : null;
    }

    /**
     * Clicked when an assigned module has no configuration page yet.
     */
    public function moduleNotConfigurable(): void
    {
        Flux::toast(text: __('No configuration is available for this module yet.'));
    }
}
