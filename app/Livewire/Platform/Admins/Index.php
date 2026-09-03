<?php

namespace App\Livewire\Platform\Admins;

use App\Actions\Platform\CreatePlatformAdmin;
use App\Enums\PlatformUserType;
use App\Models\PlatformUser;
use App\Models\Role;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Platform Admins')]
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public bool $showModal = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public ?string $role_id = null;

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public function mount(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('viewAny', PlatformUser::class);
    }

    #[Computed]
    public function admins()
    {
        return PlatformUser::query()
            ->where('user_type', PlatformUserType::PlatformAdmin)
            ->with('role')
            ->latest()
            ->paginate(10);
    }

    #[Computed]
    public function roles()
    {
        return Role::query()->whereNull('tenant_id')->orderBy('name')->get();
    }

    public function create(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('create', PlatformUser::class);

        $this->reset('editingId', 'name', 'email', 'password', 'role_id');
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $admin = PlatformUser::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $admin);

        $this->editingId = $admin->id;
        $this->name = $admin->name;
        $this->email = $admin->email;
        $this->password = '';
        $this->role_id = $admin->role_id;
        $this->showModal = true;
    }

    public function save(): void
    {
        $actor = Auth::guard('platform')->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('platform_users', 'email')->ignore($this->editingId),
            ],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        if ($this->editingId) {
            $admin = PlatformUser::findOrFail($this->editingId);
            Gate::forUser($actor)->authorize('update', $admin);

            $admin->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role_id' => $validated['role_id'],
            ]);

            if ($validated['password']) {
                $admin->password = $validated['password'];
            }

            $admin->save();
        } else {
            Gate::forUser($actor)->authorize('create', PlatformUser::class);

            app(CreatePlatformAdmin::class)->handle($validated);
        }

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('Admin saved.'));
    }

    public function toggleActive(string $id): void
    {
        $admin = PlatformUser::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $admin);

        $admin->update(['is_active' => ! $admin->is_active]);
    }

    public function confirmDelete(string $id): void
    {
        $this->confirmingDeleteId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteConfirmed(): void
    {
        if ($this->confirmingDeleteId) {
            $this->delete($this->confirmingDeleteId);
        }

        $this->showDeleteModal = false;
        $this->confirmingDeleteId = null;
    }

    public function delete(string $id): void
    {
        $admin = PlatformUser::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('delete', $admin);

        $admin->delete();

        Flux::toast(variant: 'success', text: __('Admin deleted.'));
    }
}
