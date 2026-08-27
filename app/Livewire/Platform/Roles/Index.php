<?php

namespace App\Livewire\Platform\Roles;

use App\Actions\Platform\CreatePlatformRole;
use App\Enums\ModuleScope;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Services\RoleService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Platform Roles')]
#[Layout('layouts.admin')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $name = '';

    /** @var array<int, string> */
    public array $moduleIds = [];

    /** @var array<int, string> */
    public array $permissionIds = [];

    public function mount(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('create', Role::class);
    }

    #[Computed]
    public function roles()
    {
        return Role::query()
            ->whereNull('tenant_id')
            ->with(['modules', 'permissions'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function modules()
    {
        return Module::query()->where('scope', ModuleScope::Platform)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function permissions()
    {
        return Permission::query()->get();
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'moduleIds', 'permissionIds');
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $role = Role::query()->whereNull('tenant_id')->findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $role);

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->moduleIds = $role->modules->pluck('id')->all();
        $this->permissionIds = $role->permissions->pluck('id')->all();
        $this->showModal = true;
    }

    public function save(RoleService $roleService): void
    {
        $actor = Auth::guard('platform')->user();

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->whereNull('tenant_id')->ignore($this->editingId),
            ],
            'moduleIds' => ['array'],
            'permissionIds' => ['array'],
        ]);

        if ($this->editingId) {
            $role = Role::query()->whereNull('tenant_id')->findOrFail($this->editingId);
            Gate::forUser($actor)->authorize('update', $role);

            $role->update([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
            ]);

            $roleService->syncModules($role, $this->moduleIds);
            $roleService->syncPermissions($role, $this->permissionIds);
        } else {
            Gate::forUser($actor)->authorize('create', Role::class);

            app(CreatePlatformRole::class)->handle($validated['name'], $this->moduleIds, $this->permissionIds);
        }

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('Role saved.'));
    }

    public function delete(string $id): void
    {
        $role = Role::query()->whereNull('tenant_id')->findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('delete', $role);

        app(RoleService::class)->assertDeletable($role);

        $role->delete();

        Flux::toast(variant: 'success', text: __('Role deleted.'));
    }
}
