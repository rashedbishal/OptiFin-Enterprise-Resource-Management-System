<?php

namespace App\Livewire\Tenant\LeadScoring;

use App\Enums\LeadScoringField;
use App\Enums\LeadScoringFieldKind;
use App\Enums\LeadScoringOperator;
use App\Models\LeadScoringRule;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Tenant self-service CRUD for Lead Scoring rules — Client-Admin-only (see
 * App\Policies\LeadScoringRulePolicy), same "reuse the existing 'update'
 * permission + isClientAdmin()" pattern as everywhere else scoring touches
 * authorization. Mirrors App\Livewire\Tenant\Roles\Index's
 * create/edit/save/confirmDelete/deleteConfirmed/delete shape.
 */
#[Title('Lead Scoring Rules')]
class Index extends Component
{
    public bool $showModal = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $field = '';

    public string $operator = '';

    /**
     * Free text for a scalar value, or a comma-separated list for the
     * In/NotIn operators — normalized into LeadScoringRule::$value's actual
     * JSON shape (scalar or array) in save(), never stored raw.
     */
    public string $value = '';

    public string $points = '';

    public bool $isActive = true;

    public string $sortOrder = '0';

    public bool $showDeleteModal = false;

    public ?string $confirmingDeleteId = null;

    public function mount(): void
    {
        // Whole page Client-Admin-gated, same precedent as
        // App\Livewire\Tenant\Roles\Index::mount() — a rule that can't be
        // created here can't be usefully viewed either, since this page
        // has no separate read-only mode.
        Gate::authorize('create', [LeadScoringRule::class, Auth::user()->tenant]);
    }

    #[Computed]
    public function rules()
    {
        return LeadScoringRule::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Sum of every ACTIVE rule's positive points — the realistic worst case
     * a single lead could accumulate before clamping. Purely an informational
     * UI hint ("your rules can total up to N, scores are capped at 100"),
     * never enforced: a tenant is free to over-provision.
     */
    #[Computed]
    public function maxPossibleRawTotal(): int
    {
        return (int) $this->rules()
            ->where('is_active', true)
            ->filter(fn (LeadScoringRule $rule): bool => $rule->points > 0)
            ->sum('points');
    }

    /**
     * @return array<string, string> field value => label
     */
    #[Computed]
    public function fieldOptions(): array
    {
        return collect(LeadScoringField::cases())
            ->mapWithKeys(fn (LeadScoringField $f): array => [$f->value => $f->label()])
            ->all();
    }

    /**
     * Operators valid for whichever field is currently selected in the
     * form — the UI's own field/operator-combination guard, mirrored again
     * server-side in save() so a tampered submission can't pair an invalid
     * operator with a field.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function operatorOptions(): array
    {
        $field = LeadScoringField::tryFrom($this->field);
        $operators = $field?->allowedOperators() ?? LeadScoringOperator::cases();

        return collect($operators)
            ->mapWithKeys(fn (LeadScoringOperator $o): array => [$o->value => $o->label()])
            ->all();
    }

    /**
     * The selected field's fixed value choices (Enum/Boolean kind), or null
     * for a Numeric-kind field, which takes a free-form number instead.
     *
     * @return array<string, string>|null
     */
    #[Computed]
    public function valueOptions(): ?array
    {
        return LeadScoringField::tryFrom($this->field)?->valueOptions();
    }

    #[Computed]
    public function selectedFieldKind(): ?LeadScoringFieldKind
    {
        return LeadScoringField::tryFrom($this->field)?->kind();
    }

    #[Computed]
    public function isListOperator(): bool
    {
        return in_array(LeadScoringOperator::tryFrom($this->operator), [LeadScoringOperator::In, LeadScoringOperator::NotIn], true);
    }

    /**
     * Changing the field can invalidate whatever operator/value was
     * already chosen (a different field has a different valid-operator
     * set and value catalog) — reset both rather than leave a stale,
     * now-invalid combination sitting in the form.
     */
    public function updatedField(): void
    {
        $this->operator = '';
        $this->value = '';
    }

    public function create(): void
    {
        Gate::authorize('create', [LeadScoringRule::class, Auth::user()->tenant]);

        $this->reset('editingId', 'name', 'field', 'operator', 'value', 'points', 'sortOrder');
        $this->isActive = true;
        $this->sortOrder = '0';
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(string $id): void
    {
        $rule = LeadScoringRule::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $rule);

        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->field = $rule->field;
        $this->operator = $rule->operator;
        $this->value = is_array($rule->value) ? implode(', ', $rule->value) : (string) $rule->value;
        $this->points = (string) $rule->points;
        $this->isActive = $rule->is_active;
        $this->sortOrder = (string) $rule->sort_order;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $tenant = Auth::user()->tenant;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'field' => ['required', Rule::in(LeadScoringField::values())],
            'operator' => ['required', Rule::in(LeadScoringOperator::values())],
            'value' => ['required', 'string', 'max:500'],
            'points' => ['required', 'integer', 'min:-1000', 'max:1000'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $field = LeadScoringField::from($validated['field']);
        $operator = LeadScoringOperator::from($validated['operator']);

        // Prevent an invalid field/operator combination server-side too —
        // the UI already only offers valid operators for the selected
        // field (operatorOptions()), but a tampered submission must not be
        // able to pair e.g. a numeric ">=" onto an enum field.
        if (! in_array($operator, $field->allowedOperators(), true)) {
            $this->addError('operator', __('This operator is not valid for the selected field.'));

            return;
        }

        $attributes = [
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'field' => $field->value,
            'operator' => $operator->value,
            'value' => $this->normalizeValueForStorage($field, $operator, $validated['value']),
            'points' => (int) $validated['points'],
            'is_active' => $this->isActive,
            'sort_order' => (int) $validated['sortOrder'],
        ];

        if ($this->editingId) {
            $rule = LeadScoringRule::where('tenant_id', $tenant->id)->findOrFail($this->editingId);
            Gate::authorize('update', $rule);

            $rule->update($attributes);
        } else {
            Gate::authorize('create', [LeadScoringRule::class, $tenant]);

            LeadScoringRule::create($attributes);
        }

        $this->showModal = false;
        unset($this->rules, $this->maxPossibleRawTotal);
        Flux::toast(variant: 'success', text: __('Scoring rule saved.'));
    }

    /**
     * In/NotIn store a plain array of trimmed strings; a Numeric-kind field
     * stores its comparison as a float; a Boolean-kind field stores a real
     * bool; everything else (Enum-kind, non-list operator) stores the raw
     * string as-is. This is the only place a rule's `value` column is
     * written, so it's also the single source of truth for the shape
     * App\Enums\LeadScoringOperator::evaluate() can expect to compare
     * against.
     */
    private function normalizeValueForStorage(LeadScoringField $field, LeadScoringOperator $operator, string $raw): mixed
    {
        if (in_array($operator, [LeadScoringOperator::In, LeadScoringOperator::NotIn], true)) {
            return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $v): bool => $v !== ''));
        }

        return match ($field->kind()) {
            LeadScoringFieldKind::Numeric => is_numeric($raw) ? (float) $raw : $raw,
            LeadScoringFieldKind::Boolean => match (true) {
                in_array($raw, ['1', 'true', 'yes'], true) => true,
                in_array($raw, ['0', 'false', 'no'], true) => false,
                default => $raw,
            },
            LeadScoringFieldKind::Enum => $raw,
        };
    }

    public function toggleActive(string $id): void
    {
        $rule = LeadScoringRule::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('update', $rule);

        $rule->update(['is_active' => ! $rule->is_active]);

        unset($this->rules, $this->maxPossibleRawTotal);
        Flux::toast(variant: 'success', text: $rule->is_active ? __('Rule activated.') : __('Rule deactivated.'));
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
        $rule = LeadScoringRule::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        Gate::authorize('delete', $rule);

        $rule->delete();

        unset($this->rules, $this->maxPossibleRawTotal);
        Flux::toast(variant: 'success', text: __('Scoring rule deleted.'));
    }
}
