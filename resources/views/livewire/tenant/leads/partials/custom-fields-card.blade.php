@php
    // Rendered as part of App\Livewire\Tenant\Leads\Show's own view tree
    // (included from both show.blade.php and show-panel.blade.php), so
    // $this is the Show component instance — same "call $this->x directly
    // in a shared partial" pattern deals-card.blade.php/lead-tags-manager.
    // blade.php already use.
    $canUpdate = $this->canUpdate;
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">{{ __('Custom Fields') }}</flux:heading>
        @if ($canUpdate && ! $editingCustomFields && $this->activeCustomFieldDefinitions->isNotEmpty())
            <flux:button size="sm" wire:click="startEditingCustomFields">{{ __('Edit') }}</flux:button>
        @endif
    </div>

    @if ($editingCustomFields)
        <form wire:submit="updateCustomFields" class="flex flex-col gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
                @include('livewire.tenant.leads.partials.custom-fields-form-fields', ['definitions' => $this->activeCustomFieldDefinitions])
            </div>
            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="cancelEditingCustomFields">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    @else
        @forelse ($this->customFieldDisplayRows as $row)
            <div class="flex items-center justify-between gap-2" wire:key="custom-field-display-{{ $row['definition']->id }}">
                <flux:text variant="strong">{{ $row['definition']->name }}</flux:text>
                <flux:text class="text-right">
                    {{ $row['value'] !== null && $row['value'] !== '' ? $row['value'] : '—' }}
                </flux:text>
            </div>
        @empty
            <flux:text variant="subtle">{{ __('No custom fields defined yet.') }}</flux:text>
        @endforelse
    @endif
</flux:card>
