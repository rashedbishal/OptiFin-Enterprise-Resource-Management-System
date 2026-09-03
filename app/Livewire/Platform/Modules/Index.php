<?php

namespace App\Livewire\Platform\Modules;

use App\Enums\ModuleScope;
use App\Enums\ModuleStatus;
use App\Models\Module;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Modules')]
#[Layout('layouts.admin')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $description = '';

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public function mount(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('viewAny', Module::class);
    }

    #[Computed]
    public function modules()
    {
        return Module::query()->orderBy('scope')->orderBy('sort_order')->get();
    }

    public function create(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('create', Module::class);

        $this->reset('editingId', 'name', 'description');
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $module = Module::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $module);

        $this->editingId = $module->id;
        $this->name = $module->name;
        $this->description = (string) $module->description;
        $this->showModal = true;
    }

    public function save(): void
    {
        $actor = Auth::guard('platform')->user();

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('modules', 'name')->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string'],
        ]);

        if ($this->editingId) {
            $module = Module::findOrFail($this->editingId);
            Gate::forUser($actor)->authorize('update', $module);

            $module->update([
                'name' => $validated['name'],
                'description' => $validated['description'],
            ]);
        } else {
            Gate::forUser($actor)->authorize('create', Module::class);

            Module::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
                'description' => $validated['description'],
                'scope' => ModuleScope::Tenant,
                'is_system' => false,
                'status' => ModuleStatus::Active,
                'sort_order' => Module::query()->max('sort_order') + 1,
            ]);
        }

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('Module saved.'));
    }

    public function toggleStatus(string $id): void
    {
        $module = Module::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $module);

        $module->update([
            'status' => $module->status === ModuleStatus::Active ? ModuleStatus::Inactive : ModuleStatus::Active,
        ]);
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
        $module = Module::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('delete', $module);

        $module->delete();

        Flux::toast(variant: 'success', text: __('Module deleted.'));
    }
}
