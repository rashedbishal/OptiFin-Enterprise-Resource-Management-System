@include('partials.lead-management-breadcrumbs', ['leadManagementTrail' => [['label' => __('Source Costs')]]])

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" class="hidden lg:block">{{ __('Source Costs') }}</flux:heading>
            <flux:text variant="subtle">{{ __('Record your monthly marketing spend per lead source to see Cost per Lead on the Reports page.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Cost Entry') }}</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Source') }}</flux:table.column>
            <flux:table.column>{{ __('Month') }}</flux:table.column>
            <flux:table.column>{{ __('Amount') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->costs as $cost)
                <flux:table.row wire:key="source-cost-{{ $cost->id }}">
                    <flux:table.cell>{{ $cost->source->name }}</flux:table.cell>
                    <flux:table.cell>{{ $cost->month->format('F Y') }}</flux:table.cell>
                    <flux:table.cell>{{ $cost->currency->code }} {{ number_format((float) $cost->amount, 2) }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex justify-end gap-2">
                            <flux:button size="sm" wire:click="edit('{{ $cost->id }}')">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $cost->id }}')">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">
                        {{ __('No source costs recorded yet — add one to start seeing Cost per Lead on the Reports page.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal wire:model.self="showModal" :dismissible="false" class="md:w-96">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Cost Entry') : __('New Cost Entry') }}</flux:heading>

            @php
                $sourceOpts = collect($this->eligibleSources)->map(fn($s) => ['value' => (string) $s->id, 'label' => $s->name])->toArray();
            @endphp
            <x-dropdown-select wire:model="sourceId" :label="__('Lead Source')" :placeholder="__('Select a source')" :options="$sourceOpts" />

            <flux:field>
                <flux:label>{{ __('Month') }}</flux:label>
                <input type="month" wire:model="month" class="w-full rounded-lg border border-zinc-300/80 bg-zinc-100/90 px-3 py-1.5 text-sm font-medium text-zinc-800 shadow-xs focus:outline-none focus:ring-2 focus:ring-accent/40 dark:border-zinc-700/80 dark:bg-zinc-900/90 dark:text-zinc-200" />
                @error('month') <flux:error>{{ $message }}</flux:error> @enderror
            </flux:field>

            <flux:input wire:model="amount" type="number" step="0.01" min="0" :label="__('Amount Spent')" placeholder="0.00" />

            @php
                $currencyOpts = collect($this->tenantCurrencies)->map(fn($c) => ['value' => (string) $c->id, 'label' => $c->symbolLabel().' — '.$c->name, 'short' => $c->symbolLabel()])->toArray();
            @endphp
            <x-dropdown-select wire:model="currencyId" :label="__('Currency')" :placeholder="__('Select a currency')" :options="$currencyOpts" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this cost entry?')"
        :description="__('This removes this month\'s recorded spend for this source. This cannot be undone.')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />
</div>
