<div>
    <div class="flex flex-1 flex-col gap-6">
        <x-breadcrumbs :items="[
            ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
            ['label' => __('Modules'), 'href' => route('admin.modules.index')],
            ['label' => __('Lead Management Settings')],
        ]" />

        <flux:heading size="xl" class="hidden lg:block">{{ __('Lead Management Settings') }}</flux:heading>

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="lg">{{ __('Lead Statuses') }}</flux:heading>
                <flux:text variant="subtle">
                    {{ __('The full catalog every client picks their pipeline from. A status in use by a client (selected in their pipeline, or already on one of their leads) cannot be deleted.') }}
                </flux:text>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="createStatus">{{ __('New Status') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Color') }}</flux:table.column>
                <flux:table.column>{{ __('Closed stage') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->statuses as $status)
                    <flux:table.row wire:key="status-{{ $status->id }}">
                        <flux:table.cell>{{ $status->name }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$status->color">{{ $status->color }}</flux:badge></flux:table.cell>
                        <flux:table.cell>
                            @if ($status->is_closed)
                                <flux:badge size="sm" color="zinc">{{ __('Closed') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" wire:click="editStatus('{{ $status->id }}')">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="confirmDeleteStatus('{{ $status->id }}')">
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <flux:separator />

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="lg">{{ __('Currencies') }}</flux:heading>
                <flux:text variant="subtle">
                    {{ __('The full catalog every client enables their preferred currencies from, for Est. Budget / Deal Value. A currency in use by a client (enabled for them, or already on one of their leads) cannot be deleted.') }}
                </flux:text>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="createCurrency">{{ __('New Currency') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Code') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->currencies as $currency)
                    <flux:table.row wire:key="currency-{{ $currency->id }}">
                        <flux:table.cell class="font-mono">{{ $currency->code }}</flux:table.cell>
                        <flux:table.cell>{{ $currency->name }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" wire:click="editCurrency('{{ $currency->id }}')">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="confirmDeleteCurrency('{{ $currency->id }}')">
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal wire:model.self="showStatusModal" scroll="body" class="md:w-96">
        <form wire:submit="saveStatus" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingStatusId ? __('Edit Status') : __('New Status') }}</flux:heading>

            <flux:input wire:model="statusName" :label="__('Name')" required />

            @php
                $colorOpts = collect($this->colorOptions())->map(fn($c) => ['value' => $c, 'label' => ucfirst($c)])->toArray();
            @endphp
            <x-dropdown-select wire:model="statusColor" :label="__('Color')" :options="$colorOpts" />

            <flux:checkbox wire:model="statusIsClosed" :label="__('This is a closed/final stage')" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showStatusModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showCurrencyModal" class="md:w-96">
        <form wire:submit="saveCurrency" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingCurrencyId ? __('Edit Currency') : __('New Currency') }}</flux:heading>

            <flux:input wire:model="currencyCode" :label="__('Code')" placeholder="{{ __('e.g. USD') }}" maxlength="10" required />
            <flux:input wire:model="currencyName" :label="__('Name')" placeholder="{{ __('e.g. US Dollar') }}" required />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showCurrencyModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteStatusModal"
        :title="__('Delete this status?')"
        confirm-action="deleteStatusConfirmed"
        :confirm-label="__('Delete')"
    />

    <x-confirm-modal
        wire:model.self="showDeleteCurrencyModal"
        :title="__('Delete this currency?')"
        confirm-action="deleteCurrencyConfirmed"
        :confirm-label="__('Delete')"
    />
</div>
