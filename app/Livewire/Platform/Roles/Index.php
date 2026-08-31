<?php

namespace App\Livewire\Platform\Roles;

use App\Actions\Platform\CreatePlatformRole;
use App\Enums\ModuleScope;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Services\RoleService;
use Closure;
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

    /**
     * Permission ids granted per module: module id => list of permission ids.
     * A module only counts as granted to the role while this holds at
     * least one permission id for it.
     *
     * @var array<string, array<int, string>>
     */
    public array $modulePermissions = [];

    public function mount(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('create', Role::class);
    }

    #[Computed]
    public function roles()
    {
        return Role::query()
            ->whereNull('tenant_id')
            ->with(['modules', 'modulePermissions.permission'])
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
        $this->reset('editingId', 'name', 'modulePermissions');
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $role = Role::query()->whereNull('tenant_id')->findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $role);

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->modulePermissions = $role->permissionIdsByModule();
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
            'modulePermissions' => [
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (array_filter($value) === []) {
                        $fail(__('Select at least one permission for at least one module.'));
                    }
                },
            ],
        ]);

        // A module is only granted to the role while at least one
        // permission is checked for it - drop empty selections here.
        $modulePermissions = array_filter($this->modulePermissions);
        $moduleIds = array_keys($modulePermissions);

        if ($this->editingId) {
            $role = Role::query()->whereNull('tenant_id')->findOrFail($this->editingId);
            Gate::forUser($actor)->authorize('update', $role);

            $role->update([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
            ]);

            $roleService->syncModules($role, $moduleIds);
            $roleService->syncModulePermissions($role, $modulePermissions);
        } else {
            Gate::forUser($actor)->authorize('create', Role::class);

            app(CreatePlatformRole::class)->handle($validated['name'], $moduleIds, $modulePermissions);
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
