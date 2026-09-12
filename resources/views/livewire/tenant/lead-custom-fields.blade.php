@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => __('Custom Fields')],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" class="hidden lg:block">{{ __('Custom Fields') }}</flux:heading>
            <flux:text variant="subtle">{{ __('Define extra fields your team can fill in on any lead, beyond the built-in ones.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Field') }}</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Required') }}</flux:table.column>
            <flux:table.column>{{ __('Active') }}</flux:table.column>
            <flux:table.column>{{ __('Used By') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->definitions as $definition)
                <flux:table.row wire:key="custom-field-{{ $definition->id }}">
                    <flux:table.cell>{{ $definition->name }}</flux:table.cell>
                    <flux:table.cell>{{ $definition->type->label() }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($definition->is_required)
                            <flux:badge size="sm" color="amber">{{ __('Required') }}</flux:badge>
                        @else
                            <flux:text variant="subtle" size="sm">{{ __('Optional') }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:switch wire:click="toggleActive('{{ $definition->id }}')" :checked="$definition->is_active" />
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ trans_choice(':count lead|:count leads', $definition->values_count, ['count' => $definition->values_count]) }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" wire:click="edit('{{ $definition->id }}')">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $definition->id }}')">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">
                        {{ __('No custom fields yet — create one to start capturing extra data on your leads.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal wire:model.self="showModal" :dismissible="false" class="md:w-[32rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Custom Field') : __('New Custom Field') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('e.g. Referral Source') }}" required />

            @if ($this->editingHasValues)
                {{-- Type/options are immutable once any lead has a value for
                this field (see Index::save()'s server-side enforcement of
                the same rule) — shown read-only rather than as an editable
                (but functionally locked) control, since x-dropdown-select
                has no disabled state of its own. --}}
                <div>
                    <flux:label class="mb-1 text-xs font-medium sm:text-sm">{{ __('Type') }}</flux:label>
                    <flux:text>{{ $this->typeOptions()[$type] ?? $type }}</flux:text>
                    @if ($type === 'select')
                        <flux:text size="sm" variant="subtle" class="mt-1">
                            {{ __('Options: :options', ['options' => $optionsText !== '' ? str_replace("\n", ', ', $optionsText) : '—']) }}
                        </flux:text>
                    @endif
                    <flux:text size="sm" variant="subtle" class="mt-1 block">
                        {{ __('This field already has values on one or more leads, so its type (and options, if any) can no longer be changed.') }}
                    </flux:text>
                </div>
            @else
                @php
                    $typeOpts = collect($this->typeOptions())->map(fn($label, $value) => ['value' => $value, 'label' => $label])->values()->toArray();
                @endphp
                <x-dropdown-select wire:model.live="type" :label="__('Type')" :options="$typeOpts" />

                @if ($type === 'select')
                    <flux:textarea
                        wire:model="optionsText"
                        :label="__('Options')"
                        :description="__('One option per line.')"
                        rows="4"
                    />
                @endif
            @endif

            <flux:input wire:model="sortOrder" type="number" min="0" :label="__('Display Order')" :description="__('Lower numbers show first.')" required />

            <flux:switch wire:model="isRequired" :label="__('Required')" />
            <flux:switch wire:model="isActive" :label="__('Active')" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this custom field?')"
        :description="$this->confirmingDeleteUsageCount > 0
            ? trans_choice('This field currently has a value on :count lead and cannot be deleted until that value is cleared.|This field currently has values on :count leads and cannot be deleted until those values are cleared.', $this->confirmingDeleteUsageCount, ['count' => $this->confirmingDeleteUsageCount])
            : __('This field is not currently used by any leads.')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />
</div>
