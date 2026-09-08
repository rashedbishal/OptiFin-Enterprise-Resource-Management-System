@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => __('Scoring Rules')],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" class="hidden lg:block">{{ __('Lead Scoring Rules') }}</flux:heading>
            <flux:text variant="subtle">{{ __('Every active rule below is summed for each of your leads, then capped to 0–100, to produce that lead\'s Score.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Rule') }}</flux:button>
    </div>

    @if ($this->maxPossibleRawTotal > 100)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Your active rules can total more than 100 points')">
            <flux:callout.text>
                {{ __('Right now your active rules could add up to :total points for a single lead. A lead\'s final Score is always capped at 100, so this is allowed — just worth knowing not every point will show up in the final number.', ['total' => $this->maxPossibleRawTotal]) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Condition') }}</flux:table.column>
            <flux:table.column>{{ __('Points') }}</flux:table.column>
            <flux:table.column>{{ __('Active') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->rules as $rule)
                <flux:table.row wire:key="scoring-rule-{{ $rule->id }}">
                    <flux:table.cell>{{ $rule->name }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:text size="sm">
                            {{ $rule->fieldEnum()?->label() ?? $rule->field }}
                            {{ $rule->operatorEnum()?->label() ?? $rule->operator }}
                            {{ is_array($rule->value) ? implode(', ', $rule->value) : $rule->value }}
                        </flux:text>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$rule->points >= 0 ? 'emerald' : 'red'">
                            {{ $rule->points >= 0 ? '+' : '' }}{{ $rule->points }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:switch wire:click="toggleActive('{{ $rule->id }}')" :checked="$rule->is_active" />
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" wire:click="edit('{{ $rule->id }}')">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $rule->id }}')">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5">
                        {{ __('No scoring rules yet — leads will show "Not scored" until at least one active rule exists.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal wire:model.self="showModal" :dismissible="false" class="md:w-[36rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Scoring Rule') : __('New Scoring Rule') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('e.g. High budget') }}" required />

            <x-dropdown-select wire:model.live="field" :label="__('Field')" :options="$this->fieldOptions" placeholder="{{ __('Select a field') }}" />

            <x-dropdown-select wire:model.live="operator" :label="__('Condition')" :options="$this->operatorOptions" placeholder="{{ __('Select a condition') }}" />

            @if ($this->valueOptions !== null && ! $this->isListOperator)
                <x-dropdown-select wire:model="value" :label="__('Value')" :options="$this->valueOptions" placeholder="{{ __('Select a value') }}" />
            @else
                <flux:input
                    wire:model="value"
                    :label="__('Value')"
                    :description="$this->isListOperator ? __('Comma separated, e.g. urgent, high') : ($this->selectedFieldKind === \App\Enums\LeadScoringFieldKind::Numeric ? __('A plain number, e.g. 1000000') : null)"
                    required
                />
            @endif

            <div class="flex flex-col gap-6 sm:flex-row">
                <flux:input wire:model="points" type="number" :label="__('Points')" :description="__('Can be negative — e.g. −10 for a decay rule.')" class="sm:flex-1" required />
                <flux:input wire:model="sortOrder" type="number" min="0" :label="__('Display Order')" :description="__('Display only — does not affect scoring.')" class="sm:flex-1" required />
            </div>

            <flux:switch wire:model="isActive" :label="__('Active')" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this scoring rule?')"
        :description="__('Leads already scored keep their history — only future recalculations stop counting this rule.')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />
</div>
