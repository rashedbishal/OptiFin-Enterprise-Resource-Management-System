<?php

namespace App\Livewire\Platform\Clients;

use App\Actions\Platform\AssignModuleToTenant;
use App\Actions\Platform\ManageClientAdminActivation;
use App\Actions\Platform\RemoveModuleFromTenant;
use App\Enums\ModuleScope;
use App\Enums\TenantStatus;
use App\Enums\TenantUserType;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantUser;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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

    public bool $showDeactivateClientAdminModal = false;

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

        try {
            if (in_array($moduleId, $this->assignedModuleIds, true)) {
                app(RemoveModuleFromTenant::class)->handle($this->tenant, $module);
            } else {
                app(AssignModuleToTenant::class)->handle($this->tenant, $module, Auth::guard('platform')->user());
            }
        } catch (QueryException $e) {
            Log::error('Failed to update tenant modules.', [
                'tenant_id' => $this->tenant->id,
                'module_id' => $moduleId,
                'exception' => $e,
            ]);

            Flux::toast(variant: 'danger', text: __('Something went wrong updating this client\'s modules. Please try again or contact support.'));

            return;
        }

        unset($this->assignedModuleIds);
        Flux::toast(variant: 'success', text: __('Modules updated.'));
    }

    public function updateStatus(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        // tryFrom(), not from(): $this->status is a plain wire:model-bound
        // string, so a tampered request carrying a non-enum value must be
        // rejected gracefully — never an uncaught ValueError. The tenant is
        // left untouched, same "don't mutate on bad input" discipline as
        // DealStatus/TaskStatus's own tryFrom() guard on Show.php (Leads).
        $status = TenantStatus::tryFrom($this->status);

        if ($status === null) {
            Flux::toast(variant: 'danger', text: __('That status change is not allowed.'));

            return;
        }

        $this->tenant->update(['status' => $status]);
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

    /**
     * This tenant's Client Admin account(s) — in practice always exactly
     * one (CreateClient provisions one at tenant creation; a Client Admin
     * can never create another one through the Users page, which always
     * creates a ClientUser — see App\Actions\Tenant\CreateTenantUser).
     */
    /**
     * @return Collection<int, TenantUser>
     */
    #[Computed]
    public function clientAdmins(): Collection
    {
        return $this->tenant->tenantUsers()->where('user_type', TenantUserType::ClientAdmin)->get();
    }

    #[Computed]
    public function anyClientAdminActive(): bool
    {
        return $this->clientAdmins()->contains('is_active', true);
    }

    public function confirmDeactivateClientAdmin(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        $this->showDeactivateClientAdminModal = true;
    }

    /**
     * Suspends this tenant's Client Admin and every other active tenant
     * user alongside them — see ManageClientAdminActivation's own
     * docblock for why. Confirmed via a modal first (deactivateClientAdmin()
     * is the authoritative, re-checked action; confirmDeactivateClientAdmin()
     * just opens the modal) since this is a wide-blast-radius action, not a
     * single-row toggle like the tenant-side Users page's own toggleActive().
     */
    public function deactivateClientAdminConfirmed(): void
    {
        $this->showDeactivateClientAdminModal = false;
        $this->deactivateClientAdmin();
    }

    public function deactivateClientAdmin(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        app(ManageClientAdminActivation::class)->deactivate($this->tenant);

        unset($this->clientAdmins, $this->anyClientAdminActive);
        Flux::toast(variant: 'success', text: __('Client Admin and this tenant\'s users have been deactivated.'));
    }

    public function activateClientAdmin(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $this->tenant);

        app(ManageClientAdminActivation::class)->activate($this->tenant);

        unset($this->clientAdmins, $this->anyClientAdminActive);
        Flux::toast(variant: 'success', text: __('Client Admin and this tenant\'s affected users have been reactivated.'));
    }
}
