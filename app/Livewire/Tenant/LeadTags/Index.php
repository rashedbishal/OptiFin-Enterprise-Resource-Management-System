<?php

namespace App\Livewire\Tenant\LeadTags;

use App\Models\LeadTag;
use Flux\Flux;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Tenant self-service CRUD for the Lead Tag catalog — Client-Admin-only (see
 * App\Policies\LeadTagPolicy), same "reuse the existing 'update' permission
 * + isClientAdmin()" pattern as Lead Scoring Rules. Mirrors
 * App\Livewire\Tenant\LeadScoring\Index's create/edit/save/confirmDelete/
 * deleteConfirmed/delete shape.
 */
#[Title('Lead Tags')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $color = 'blue';

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public function mount(): void
    {
        // Whole page Client-Admin-gated, same precedent as
        // App\Livewire\Tenant\LeadScoring\Index::mount() — a tag that can't
        // be created here can't be usefully viewed either, since this page
        // has no separate read-only mode.
        Gate::authorize('viewAny', LeadTag::class);
    }

    #[Computed]
    public function tags()
    {
        return LeadTag::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->withCount('leads')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function colorOptions(): array
    {
        return LeadTag::availableColors();
    }

    public function create(): void
    {
        Gate::authorize('create', [LeadTag::class, Auth::user()->tenant]);

        $this->reset('editingId', 'name');
        $this->color = 'blue';
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $tag = LeadTag::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $tag);

        $this->editingId = $tag->id;
        $this->name = $tag->name;
        $this->color = $tag->color;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $tenant = Auth::user()->tenant;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', Rule::in(LeadTag::availableColors())],
        ]);

        $slug = Str::slug($validated['name']);

        $slugTaken = LeadTag::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->exists();

        if ($slugTaken) {
            $this->addError('name', __('A tag with this name already exists.'));

            return;
        }

        // Not atomic with the slugTaken check above — see
        // Settings::saveStatus()'s identical reasoning. slug has a real
        // unique(tenant_id, slug) constraint (see the migration), so a
        // genuine concurrent race can't produce a duplicate row, but
        // without this catch the loser gets an uncaught
        // UniqueConstraintViolationException instead of the same graceful
        // validation error.
        try {
            if ($this->editingId) {
                $tag = LeadTag::where('tenant_id', $tenant->id)->findOrFail($this->editingId);
                Gate::authorize('update', $tag);

                $tag->update([
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'color' => $validated['color'],
                ]);
            } else {
                Gate::authorize('create', [LeadTag::class, $tenant]);

                LeadTag::create([
                    'tenant_id' => $tenant->id,
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'color' => $validated['color'],
                ]);
            }
        } catch (UniqueConstraintViolationException) {
            $this->addError('name', __('A tag with this name already exists.'));

            return;
        }

        $this->showModal = false;
        unset($this->tags);
        Flux::toast(variant: 'success', text: __('Tag saved.'));
    }

    /**
     * How many of this tenant's leads currently carry the tag pending
     * deletion — surfaced in the delete confirmation modal so a Client
     * Admin knows exactly what deleting it will detach before confirming.
     */
    #[Computed]
    public function confirmingDeleteUsageCount(): int
    {
        if (! $this->confirmingDeleteId) {
            return 0;
        }

        return LeadTag::where('tenant_id', Auth::user()->tenant_id)
            ->whereKey($this->confirmingDeleteId)
            ->first()
            ?->leads()
            ->count() ?? 0;
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

    /**
     * Deleting a tag detaches it from every lead that currently carries it
     * (via the lead_lead_tag pivot's cascadeOnDelete on lead_tag_id) —
     * tenant-local, self-service catalog, so there's no cross-tenant
     * referential risk to protect against the way LeadStatus/Currency's
     * delete-when-in-use block does. The confirmation modal's usage count
     * (see confirmingDeleteUsageCount()) is what gives the admin a chance
     * to back out first.
     */
    public function delete(string $id): void
    {
        $tag = LeadTag::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('delete', $tag);

        $tag->delete();

        unset($this->tags);
        Flux::toast(variant: 'success', text: __('Tag deleted.'));
    }
}
