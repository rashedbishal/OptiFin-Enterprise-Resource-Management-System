<div>
    <div class="flex flex-1 flex-col gap-6">
        <x-breadcrumbs :items="[
            ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
            ['label' => __('Modules')],
        ]" />

        <div class="flex items-center justify-between">
            <flux:heading size="xl" class="hidden lg:block">{{ __('Modules') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Module') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Scope') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->modules as $module)
                    <flux:table.row wire:key="module-{{ $module->id }}">
                        <flux:table.cell>{{ $module->name }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ ucfirst($module->scope->value) }}</flux:badge></flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$module->status->value === 'active' ? 'green' : 'zinc'" size="sm">
                                {{ ucfirst($module->status->value) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" wire:click="edit('{{ $module->id }}')">{{ __('Edit') }}</flux:button>
                                @if ($module->slug === 'lead-management')
                                    <flux:button size="sm" :href="route('admin.lead-statuses.index')" wire:navigate>{{ __('Configuration') }}</flux:button>
                                @endif
                                <flux:button size="sm" wire:click="toggleStatus('{{ $module->id }}')">
                                    {{ $module->status->value === 'active' ? __('Deactivate') : __('Activate') }}
                                </flux:button>
                                @unless ($module->is_system)
                                    <flux:button size="sm" variant="danger" wire:click="delete('{{ $module->id }}')" wire:confirm="{{ __('Delete this module?') }}">
                                        {{ __('Delete') }}
                                    </flux:button>
                                @endunless
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal wire:model.self="showModal" class="md:w-96">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Module') : __('New Module') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:textarea wire:model="description" :label="__('Description')" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
