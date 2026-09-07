<div wire:init="completeInitialLoad">
    <div class="flex flex-1 flex-col gap-6">
        @php
            // No filters on this page — completeInitialLoad() (fired once
            // via wire:init above) is the whole target list for both
            // tables below. See leads.blade.php's identical
            // $showSkeletonByDefault for why the skeleton's/real content's
            // STATIC class (not just their wire:loading toggle) has to be
            // conditional on server-known state.
            $listLoadingTargets = 'completeInitialLoad';
            $showSkeletonByDefault = ! $initialLoadComplete;
        @endphp

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
                    <flux:table.row wire:key="status-{{ $status->id }}" wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
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

            {{-- Skeleton: a second <tbody>, hidden by default and revealed
            only while loading — see leads.blade.php for why this can't be a
            bare wire:loading directive. Row count is arbitrary (3), this
            catalog is never paginated. --}}
            <flux:table.rows @class(['!hidden' => ! $showSkeletonByDefault]) wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
                @for ($i = 0; $i < 3; $i++)
                    <flux:table.row wire:key="status-skeleton-{{ $i }}">
                        <flux:table.cell><div class="h-3 w-24 skeleton-shimmer rounded"></div></flux:table.cell>
                        <flux:table.cell><div class="h-5 w-16 skeleton-shimmer rounded-full"></div></flux:table.cell>
                        <flux:table.cell><div class="h-5 w-14 skeleton-shimmer rounded-full"></div></flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end">
                                <div class="h-6 w-24 skeleton-shimmer rounded"></div>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endfor
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
                    <flux:table.row wire:key="currency-{{ $currency->id }}" wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" @class(['!hidden' => $showSkeletonByDefault])>
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

            {{-- Skeleton: a second <tbody>, hidden by default and revealed
            only while loading — see leads.blade.php for why this can't be a
            bare wire:loading directive. Row count is arbitrary (2), this
            catalog is never paginated. --}}
            <flux:table.rows @class(['!hidden' => ! $showSkeletonByDefault]) wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
                @for ($i = 0; $i < 2; $i++)
                    <flux:table.row wire:key="currency-skeleton-{{ $i }}">
                        <flux:table.cell><div class="h-3 w-12 skeleton-shimmer rounded"></div></flux:table.cell>
                        <flux:table.cell><div class="h-3 w-28 skeleton-shimmer rounded"></div></flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end">
                                <div class="h-6 w-24 skeleton-shimmer rounded"></div>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endfor
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
