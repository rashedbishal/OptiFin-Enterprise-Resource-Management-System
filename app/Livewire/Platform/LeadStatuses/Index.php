<?php

namespace App\Livewire\Platform\LeadStatuses;

use App\Models\Lead;
use App\Models\LeadStatus;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Lead Statuses')]
#[Layout('layouts.admin')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $color = 'blue';

    public bool $isClosed = false;

    public function mount(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('viewAny', LeadStatus::class);
    }

    #[Computed]
    public function statuses()
    {
        return LeadStatus::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @return array<int, string>
     */
    public function colorOptions(): array
    {
        return LeadStatus::availableColors();
    }

    public function create(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('create', LeadStatus::class);

        $this->reset('editingId', 'name', 'isClosed');
        $this->color = 'blue';
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $status = LeadStatus::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('update', $status);

        $this->editingId = $status->id;
        $this->name = $status->name;
        $this->color = $status->color;
        $this->isClosed = $status->is_closed;
        $this->showModal = true;
    }

    public function save(): void
    {
        $actor = Auth::guard('platform')->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', Rule::in(LeadStatus::availableColors())],
            'isClosed' => ['boolean'],
        ]);

        $slug = Str::slug($validated['name']);

        $slugTaken = LeadStatus::query()
            ->where('slug', $slug)
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->exists();

        if ($slugTaken) {
            $this->addError('name', __('A status with this name already exists.'));

            return;
        }

        if ($this->editingId) {
            $status = LeadStatus::findOrFail($this->editingId);
            Gate::forUser($actor)->authorize('update', $status);

            $status->update([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
                'color' => $validated['color'],
                'is_closed' => $validated['isClosed'],
            ]);
        } else {
            Gate::forUser($actor)->authorize('create', LeadStatus::class);

            LeadStatus::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
                'color' => $validated['color'],
                'is_closed' => $validated['isClosed'],
                'sort_order' => LeadStatus::query()->max('sort_order') + 1,
            ]);
        }

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('Lead status saved.'));
    }

    public function delete(string $id): void
    {
        $status = LeadStatus::findOrFail($id);

        Gate::forUser(Auth::guard('platform')->user())->authorize('delete', $status);

        if (Lead::where('status_id', $status->id)->exists() || DB::table('tenant_lead_statuses')->where('lead_status_id', $status->id)->exists()) {
            Flux::toast(variant: 'danger', text: __('This status is in use by a client and cannot be deleted.'));

            return;
        }

        $status->delete();

        Flux::toast(variant: 'success', text: __('Lead status deleted.'));
    }
}
