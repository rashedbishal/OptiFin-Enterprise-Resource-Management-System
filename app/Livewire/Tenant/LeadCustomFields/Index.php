<?php

namespace App\Livewire\Tenant\LeadCustomFields;

use App\Enums\LeadCustomFieldType;
use App\Models\LeadCustomFieldDefinition;
use Flux\Flux;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Feature #11 — tenant self-service CRUD for the Lead Custom Field catalog.
 * Client-Admin-only (see App\Policies\LeadCustomFieldDefinitionPolicy), same
 * "reuse the existing 'update' permission + isClientAdmin()" pattern as
 * Lead Scoring Rules/Lead Tags. Mirrors
 * App\Livewire\Tenant\LeadTags\Index/App\Livewire\Tenant\LeadScoring\Index's
 * create/edit/save/toggleActive/confirmDelete/deleteConfirmed/delete shape.
 */
#[Title('Custom Fields')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $type = 'text';

    /**
     * Newline-separated free text for a `select` field's options — parsed
     * into a plain trimmed, de-duplicated, non-empty array in save(), never
     * stored raw. Ignored entirely for every other type.
     */
    public string $optionsText = '';

    public bool $isRequired = false;

    public bool $isActive = true;

    public string $sortOrder = '0';

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public function mount(): void
    {
        // Whole page Client-Admin-gated, same precedent as
        // App\Livewire\Tenant\LeadTags\Index::mount() — a field that can't
        // be created here can't be usefully viewed either, since this page
        // has no separate read-only mode.
        Gate::authorize('viewAny', LeadCustomFieldDefinition::class);
    }

    #[Computed]
    public function definitions()
    {
        return LeadCustomFieldDefinition::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->withCount('values')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return array<string, string> type value => label
     */
    public function typeOptions(): array
    {
        return collect(LeadCustomFieldType::cases())
            ->mapWithKeys(fn (LeadCustomFieldType $t): array => [$t->value => $t->label()])
            ->all();
    }

    public function create(): void
    {
        Gate::authorize('create', [LeadCustomFieldDefinition::class, Auth::user()->tenant]);

        $this->reset('editingId', 'name', 'optionsText', 'sortOrder');
        $this->type = LeadCustomFieldType::Text->value;
        $this->isRequired = false;
        $this->isActive = true;
        $this->sortOrder = '0';
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $definition = LeadCustomFieldDefinition::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $definition);

        $this->editingId = $definition->id;
        $this->name = $definition->name;
        $this->type = $definition->type->value;
        $this->optionsText = implode("\n", $definition->options ?? []);
        $this->isRequired = $definition->is_required;
        $this->isActive = $definition->is_active;
        $this->sortOrder = (string) $definition->sort_order;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    /**
     * Whether the definition currently being edited already has at least
     * one value on some lead — drives the form's read-only lock on
     * type/options (see save()'s server-side enforcement of the same rule).
     */
    #[Computed]
    public function editingHasValues(): bool
    {
        if (! $this->editingId) {
            return false;
        }

        return LeadCustomFieldDefinition::where('tenant_id', Auth::user()->tenant_id)
            ->whereKey($this->editingId)
            ->first()
            ?->hasValues() ?? false;
    }

    public function save(): void
    {
        $tenant = Auth::user()->tenant;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(LeadCustomFieldType::values())],
            'isRequired' => ['boolean'],
            'isActive' => ['boolean'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $type = LeadCustomFieldType::from($validated['type']);

        $options = null;

        if ($type === LeadCustomFieldType::Select) {
            $options = array_values(array_unique(array_filter(
                array_map('trim', explode("\n", $this->optionsText)),
                fn (string $option): bool => $option !== ''
            )));

            if ($options === []) {
                $this->addError('optionsText', __('Provide at least one option, one per line.'));

                return;
            }
        }

        $key = Str::slug($validated['name']);

        $keyTaken = LeadCustomFieldDefinition::query()
            ->where('tenant_id', $tenant->id)
            ->where('key', $key)
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->exists();

        if ($keyTaken) {
            $this->addError('name', __('A custom field with this name already exists.'));

            return;
        }

        $attributes = [
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'key' => $key,
            'type' => $type,
            'options' => $options,
            'is_required' => $this->isRequired,
            'is_active' => $this->isActive,
            'sort_order' => (int) $validated['sortOrder'],
        ];

        // Not atomic with the keyTaken check above — see
        // LeadTags\Index::save()'s identical reasoning. key has a real
        // unique(tenant_id, key) constraint (see the migration), so a
        // genuine concurrent race can't produce a duplicate row, but
        // without this catch the loser gets an uncaught
        // UniqueConstraintViolationException instead of the same graceful
        // validation error.
        try {
            if ($this->editingId) {
                $definition = LeadCustomFieldDefinition::where('tenant_id', $tenant->id)->findOrFail($this->editingId);
                Gate::authorize('update', $definition);

                // Type/options are immutable once any lead has a value for
                // this field — there is no safe, defined reinterpretation of
                // existing typed data across a type/option-list change, so
                // this is blocked outright rather than silently
                // transforming or discarding those values (same "block,
                // don't cascade/transform" spirit as the delete-in-use rule
                // below).
                if ($definition->hasValues() && ($definition->type !== $type || $definition->options !== $options)) {
                    $this->addError('type', __('This field already has values on one or more leads, so its type and options can no longer be changed.'));

                    return;
                }

                $definition->update($attributes);
            } else {
                Gate::authorize('create', [LeadCustomFieldDefinition::class, $tenant]);

                LeadCustomFieldDefinition::create($attributes);
            }
        } catch (UniqueConstraintViolationException) {
            $this->addError('name', __('A custom field with this name already exists.'));

            return;
        }

        $this->showModal = false;
        unset($this->definitions, $this->editingHasValues);
        Flux::toast(variant: 'success', text: __('Custom field saved.'));
    }

    public function toggleActive(string $id): void
    {
        $definition = LeadCustomFieldDefinition::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $definition);

        $definition->update(['is_active' => ! $definition->is_active]);

        unset($this->definitions);
        Flux::toast(variant: 'success', text: $definition->is_active ? __('Field activated.') : __('Field deactivated.'));
    }

    /**
     * How many of this tenant's leads currently carry a value for the field
     * pending deletion — surfaced in the delete confirmation modal, same UI
     * convention as LeadTags\Index::confirmingDeleteUsageCount(), even
     * though the outcome on confirm differs (Tags cascades, this refuses —
     * see delete() below).
     */
    #[Computed]
    public function confirmingDeleteUsageCount(): int
    {
        if (! $this->confirmingDeleteId) {
            return 0;
        }

        return LeadCustomFieldDefinition::where('tenant_id', Auth::user()->tenant_id)
            ->whereKey($this->confirmingDeleteId)
            ->first()
            ?->values()
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
     * Deleting a custom field is BLOCKED outright while any lead still
     * carries a value for it — unlike LeadTag's cascade-on-delete, this
     * follows the LeadStatus/Currency "refuse, don't cascade" pattern
     * (Settings::deleteCurrency()), since silently discarding populated
     * business-data values would be a real data loss, not just detaching a
     * label.
     *
     * Locks the LeadCustomFieldDefinition row before checking dependents so
     * a concurrent Lead custom-field save or Website Form field change
     * cannot create a new dependency between the check and the delete —
     * same "lock the stable parent row" idiom already used by
     * Platform\Clients\LeadSources::delete() (C1), Tenant\Leads\Index::
     * deleteSource(), and Platform\LeadManagement\Settings::deleteStatus()/
     * deleteCurrency().
     */
    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $definition = LeadCustomFieldDefinition::lockForUpdate()
                ->where('tenant_id', Auth::user()->tenant_id)
                ->findOrFail($id);

            Gate::authorize('delete', $definition);

            if ($definition->hasValues()) {
                Flux::toast(variant: 'danger', text: __('This field has values on one or more leads and cannot be deleted.'));

                return;
            }

            // Feature #13: a definition still exposed by a Website Form must
            // not be deletable out from under it either, even with zero
            // submitted values yet — same "don't leave a dangling reference"
            // principle as the hasValues() check above.
            if ($definition->isReferencedByWebsiteForm()) {
                Flux::toast(variant: 'danger', text: __('This field is used by one or more Website Forms and cannot be deleted.'));

                return;
            }

            $definition->delete();

            unset($this->definitions);
            Flux::toast(variant: 'success', text: __('Custom field deleted.'));
        });
    }
}
