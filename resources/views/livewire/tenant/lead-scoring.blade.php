@include('partials.lead-management-breadcrumbs', ['leadManagementTrail' => [['label' => __('Scoring Rules')]]])

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-col gap-1 min-w-0">
            <flux:heading size="xl">{{ __('Lead Scoring Rules') }}</flux:heading>
            <flux:text class="text-zinc-600 dark:text-zinc-300 font-medium text-sm sm:text-base">{{ __('Every active rule below is summed for each of your leads, then capped to 0–100, to produce that lead\'s Score.') }}</flux:text>
        </div>
        <div class="shrink-0">
            <flux:button variant="primary" icon="plus" wire:click="create" class="w-full sm:w-auto">
                {{ __('New Rule') }}
            </flux:button>
        </div>
    </div>

    @if ($this->maxPossibleRawTotal > 100)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Your active rules can total more than 100 points')">
            <flux:callout.text>
                {{ __('Right now your active rules could add up to :total points for a single lead. A lead\'s final Score is always capped at 100, so this is allowed — just worth knowing not every point will show up in the final number.', ['total' => $this->maxPossibleRawTotal]) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Desktop Table View (>= 768px / md) --}}
    <div class="hidden md:block">
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
                    <flux:table.row wire:key="scoring-rule-desktop-{{ $rule->id }}">
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">{{ $rule->name }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:text size="sm" class="text-zinc-800 dark:text-zinc-200 font-medium">
                                {{ $rule->fieldEnum()?->label() ?? $rule->field }}
                                <span class="text-indigo-600 dark:text-indigo-400 font-semibold">{{ $rule->operatorEnum()?->label() ?? $rule->operator }}</span>
                                <span class="font-bold text-zinc-900 dark:text-white">{{ is_array($rule->value) ? implode(', ', $rule->value) : $rule->value }}</span>
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
    </div>

    {{-- Mobile & Tablet Full-Width Card View (< 768px / md) --}}
    <div class="flex flex-col gap-3.5 w-full md:hidden">
        @forelse ($this->rules as $rule)
            <div wire:key="scoring-rule-card-{{ $rule->id }}" class="flex w-full flex-col gap-3 rounded-xl border border-zinc-200/90 bg-white p-4 shadow-2xs dark:border-zinc-700/80 dark:bg-zinc-800/90">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex flex-col gap-0.5 min-w-0">
                        <span class="font-bold text-zinc-900 dark:text-white text-base truncate">{{ $rule->name }}</span>
                    </div>
                    <flux:badge size="sm" :color="$rule->points >= 0 ? 'emerald' : 'red'" class="shrink-0 font-semibold">
                        {{ $rule->points >= 0 ? '+' : '' }}{{ $rule->points }} {{ __('pts') }}
                    </flux:badge>
                </div>

                <div class="flex flex-col gap-1 rounded-lg bg-zinc-50 p-3 border border-zinc-200/80 dark:bg-zinc-900/60 dark:border-zinc-700/60">
                    <span class="text-xs uppercase tracking-wider font-bold text-zinc-700 dark:text-zinc-300">{{ __('Condition') }}</span>
                    <span class="text-xs sm:text-sm font-medium text-zinc-900 dark:text-zinc-100">
                        {{ $rule->fieldEnum()?->label() ?? $rule->field }}
                        <span class="text-indigo-600 dark:text-indigo-400 font-bold">{{ $rule->operatorEnum()?->label() ?? $rule->operator }}</span>
                        <span class="font-bold text-zinc-900 dark:text-white">{{ is_array($rule->value) ? implode(', ', $rule->value) : $rule->value }}</span>
                    </span>
                </div>

                <div class="flex items-center justify-between gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-700/60">
                    <div class="flex items-center gap-2">
                        <flux:switch wire:click="toggleActive('{{ $rule->id }}')" :checked="$rule->is_active" />
                        <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                            {{ $rule->is_active ? __('Active') : __('Inactive') }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" wire:click="edit('{{ $rule->id }}')">{{ __('Edit') }}</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $rule->id }}')">
                            {{ __('Delete') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        @empty
            <div class="w-full rounded-xl border border-zinc-200/90 bg-white p-6 text-center shadow-2xs dark:border-zinc-700/80 dark:bg-zinc-800/90">
                <flux:text class="text-zinc-700 dark:text-zinc-300 font-medium">
                    {{ __('No scoring rules yet — leads will show "Not scored" until at least one active rule exists.') }}
                </flux:text>
            </div>
        @endforelse
    </div>

    <flux:modal wire:model.self="showModal" :dismissible="false" class="md:w-[36rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Scoring Rule') : __('New Scoring Rule') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('e.g. High budget') }}" required />

            <x-dropdown-select wire:model.live="field" :label="__('Field')" :options="$this->fieldOptions" placeholder="{{ __('Select a field') }}" />

            <x-dropdown-select wire:model.live="operator" :label="__('Condition')" :options="$this->operatorOptions" placeholder="{{ __('Select a condition') }}" />

            @if ($this->valueOptions !== null && ! $this->isListOperator)
                {{--
                    wire:key is load-bearing here, not decorative: x-dropdown-select
                    bakes its `options: {{ json_encode(...) }}` array into Alpine's
                    x-data ONCE, the first time this element mounts — Alpine never
                    re-evaluates x-data on a later Livewire morph/patch of the SAME
                    DOM node, only on a full destroy+recreate. Without a wire:key
                    that changes every time the form opens, reopening this same
                    modal (create() or edit(), even for the exact same rule/field
                    twice in a row) leaves this dropdown showing whatever options
                    array was baked in the FIRST time it ever mounted in this
                    browser tab — which can go stale after a valueOptions() change
                    ships, even though every subsequent server response already
                    reflects it. Keyed on TWO things: $formInstance (increments
                    on every create()/edit() call, so reopening the modal always
                    remounts fresh) AND $field itself (so switching between two
                    Enum-kind fields — e.g. Priority to Source Channel — inside
                    an ALREADY-open form, which keeps the same $formInstance,
                    still forces a remount instead of showing the previous
                    field's options under the new field's selection).
                --}}
                <x-dropdown-select wire:key="scoring-rule-value-{{ $formInstance }}-{{ $field }}" wire:model="value" :label="__('Value')" :options="$this->valueOptions" placeholder="{{ __('Select a value') }}" />
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
