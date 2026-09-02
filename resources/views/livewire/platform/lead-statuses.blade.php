<div>
    <div class="flex flex-1 flex-col gap-6">
        <x-breadcrumbs :items="[
            ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
            ['label' => __('Modules'), 'href' => route('admin.modules.index')],
            ['label' => __('Lead Statuses')],
        ]" />

        <div class="flex items-center justify-between">
            <flux:heading size="xl">{{ __('Lead Statuses') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Status') }}</flux:button>
        </div>

        <flux:text variant="subtle">
            {{ __('The full catalog every client picks their pipeline from. A status in use by a client (selected in their pipeline, or already on one of their leads) cannot be deleted.') }}
        </flux:text>

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
                                <flux:button size="sm" wire:click="edit('{{ $status->id }}')">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="delete('{{ $status->id }}')" wire:confirm="{{ __('Delete this status?') }}">
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal wire:model.self="showModal" class="md:w-96">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Status') : __('New Status') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />

            <flux:select wire:model="color" :label="__('Color')">
                @foreach ($this->colorOptions() as $option)
                    <flux:select.option value="{{ $option }}">{{ ucfirst($option) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox wire:model="isClosed" :label="__('This is a closed/final stage')" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
