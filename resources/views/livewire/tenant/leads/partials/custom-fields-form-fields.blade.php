{{--
    Feature #11 — shared by the New Lead modal (leads.blade.php, via
    App\Livewire\Tenant\Leads\Index) and the Lead detail/Kanban-flyout
    "Custom Fields" edit form (custom-fields-card.blade.php, via
    App\Livewire\Tenant\Leads\Show) — both components expose an identically-
    shaped `public array $customFieldValues` property keyed by
    lead_custom_field_definition_id, so this one partial renders correctly
    against either. $definitions is the tenant's own active
    LeadCustomFieldDefinition collection, in display order. A fixed 4-branch
    switch on LeadCustomFieldType — not a generic dynamic-form engine — since
    Phase 1 supports exactly these four types and no others.
--}}
@foreach ($definitions as $definition)
    <div wire:key="custom-field-input-{{ $definition->id }}">
        @switch($definition->type->value)
            @case('text')
                <flux:input
                    wire:model="customFieldValues.{{ $definition->id }}"
                    :label="$definition->name"
                    :required="$definition->is_required"
                />
                @break

            @case('number')
                <flux:input
                    wire:model="customFieldValues.{{ $definition->id }}"
                    type="number"
                    step="0.01"
                    :label="$definition->name"
                    :required="$definition->is_required"
                />
                @break

            @case('date')
                <x-date-picker
                    wire:model="customFieldValues.{{ $definition->id }}"
                    :label="$definition->name"
                    :placeholder="$definition->name"
                />
                @break

            @case('select')
                <x-dropdown-select
                    wire:model="customFieldValues.{{ $definition->id }}"
                    :label="$definition->name"
                    :options="$definition->options ?? []"
                    :placeholder="__('Select...')"
                />
                @break
        @endswitch
    </div>
@endforeach
