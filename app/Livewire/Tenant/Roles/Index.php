<?php

namespace App\Livewire\Tenant\Roles;

use App\Actions\Tenant\CreateTenantRole;
use App\Models\Permission;
use App\Models\Role;
use App\Services\RoleService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Roles')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $name = '';

    /** @var array<int, string> */
    public array $moduleIds = [];

    /**
     * Permission ids granted per module: module id => list of permission ids.
     *
     * @var array<string, array<int, string>>
     */
    public array $modulePermissions = [];

    public function mount(): void
    {
        Gate::authorize('create', [Role::class, Auth::user()->tenant]);
    }

    #[Computed]
    public function roles()
    {
        return Role::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->with(['modules', 'modulePermissions.permission'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function availableModules()
    {
        return Auth::user()->tenant->activeModules;
    }

    #[Computed]
    public function permissions()
    {
        return Permission::query()->get();
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'moduleIds', 'modulePermissions');
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $role = Role::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $role);

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->moduleIds = $role->modules->pluck('id')->all();
        $this->modulePermissions = $role->permissionIdsByModule();
        $this->showModal = true;
    }

    /**
     * Drop permission selections for modules that were just unchecked, so
     * a re-checked module doesn't come back with stale grants attached.
     */
    public function updatedModuleIds(): void
    {
        $this->modulePermissions = collect($this->modulePermissions)->only($this->moduleIds)->all();
    }

    public function save(RoleService $roleService): void
    {
        $tenant = Auth::user()->tenant;

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->where('tenant_id', $tenant->id)->ignore($this->editingId),
            ],
            'moduleIds' => ['array'],
            'modulePermissions' => ['array'],
        ]);

        $modulePermissions = array_intersect_key($this->modulePermissions, array_flip($this->moduleIds));

        if ($this->editingId) {
            $role = Role::where('tenant_id', $tenant->id)->findOrFail($this->editingId);
            Gate::authorize('update', $role);

            $role->update([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
            ]);

            $roleService->syncModules($role, $this->moduleIds);
            $roleService->syncModulePermissions($role, $modulePermissions);
        } else {
            Gate::authorize('create', [Role::class, $tenant]);

            app(CreateTenantRole::class)->handle($tenant, $validated['name'], $this->moduleIds, $modulePermissions);
        }

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('Role saved.'));
    }

    public function delete(string $id): void
    {
        $role = Role::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('delete', $role);

        app(RoleService::class)->assertDeletable($role);

        $role->delete();

        Flux::toast(variant: 'success', text: __('Role deleted.'));
    }
}
