<?php

namespace App\Livewire\Platform\Clients;

use App\Actions\Platform\AssignModuleToTenant;
use App\Actions\Platform\DeleteTenant;
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
use Illuminate\Support\Facades\Hash;
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

    public bool $showDeleteTenantStep1Modal = false;

    public bool $showDeleteTenantStep2Modal = false;

    public string $deleteTenantPassword1 = '';

    public string $deleteTenantPassword2 = '';

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
     *
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

    /**
     * Permanently deletes this tenant and everything it owns — Mother
     * Admin only (TenantPolicy::delete()), and only once every Client
     * Admin is already deactivated (see anyClientAdminActive()), since
     * ManageClientAdminActivation::deactivate() is what suspends every
     * other tenant user alongside them; this is the one, deliberate
     * precondition rather than this action trying to re-derive "is it
     * safe to erase this tenant's users" itself. Two separate password
     * confirmations, not one — this is irreversible and wipes real
     * client data, a deliberately heavier gate than the single-password
     * lead-delete confirm elsewhere in the app.
     */
    public function confirmDeleteTenant(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('delete', $this->tenant);

        if ($this->anyClientAdminActive()) {
            Flux::toast(variant: 'danger', text: __('Deactivate the Client Admin first — this tenant cannot be deleted while any of its users are still active.'));

            return;
        }

        $this->reset('deleteTenantPassword1', 'deleteTenantPassword2');
        $this->resetErrorBag();
        $this->showDeleteTenantStep1Modal = true;
    }

    public function closeDeleteTenantStep1Modal(): void
    {
        $this->showDeleteTenantStep1Modal = false;
        $this->reset('deleteTenantPassword1');
        $this->resetErrorBag('deleteTenantPassword1');
    }

    public function deleteTenantStep1Confirmed(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('delete', $this->tenant);

        $this->validate(['deleteTenantPassword1' => ['required', 'string']]);

        if (! Hash::check($this->deleteTenantPassword1, Auth::guard('platform')->user()->password)) {
            $this->addError('deleteTenantPassword1', __('The password is incorrect.'));

            return;
        }

        $this->showDeleteTenantStep1Modal = false;
        $this->showDeleteTenantStep2Modal = true;
    }

    public function closeDeleteTenantStep2Modal(): void
    {
        $this->showDeleteTenantStep2Modal = false;
        $this->reset('deleteTenantPassword1', 'deleteTenantPassword2');
        $this->resetErrorBag('deleteTenantPassword2');
    }

    /**
     * The authoritative step — every prior check (permission, the
     * already-deactivated precondition, the first password) is advisory
     * up to this point; this re-checks all of them itself before actually
     * calling DeleteTenant, the same discipline Lead deletion elsewhere in
     * this app already uses.
     */
    public function deleteTenantStep2Confirmed(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('delete', $this->tenant);

        if ($this->anyClientAdminActive()) {
            Flux::toast(variant: 'danger', text: __('Deactivate the Client Admin first — this tenant cannot be deleted while any of its users are still active.'));

            $this->showDeleteTenantStep2Modal = false;

            return;
        }

        $this->validate(['deleteTenantPassword2' => ['required', 'string']]);

        if (! Hash::check($this->deleteTenantPassword2, Auth::guard('platform')->user()->password)) {
            $this->addError('deleteTenantPassword2', __('The password is incorrect.'));

            return;
        }

        $tenantName = $this->tenant->name;

        app(DeleteTenant::class)->handle($this->tenant);

        Flux::toast(variant: 'success', text: __(':name has been permanently deleted.', ['name' => $tenantName]));

        $this->redirect(route('admin.clients.index'), navigate: true);
    }
}
