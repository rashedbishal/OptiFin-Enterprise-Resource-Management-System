<div>
    <div class="flex flex-1 flex-col gap-6">
        <div class="flex items-center justify-between">
            <flux:heading size="xl">{{ __('Platform Admins') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Admin') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->admins as $admin)
                    <flux:table.row wire:key="admin-{{ $admin->id }}">
                        <flux:table.cell>{{ $admin->name }}</flux:table.cell>
                        <flux:table.cell>{{ $admin->email }}</flux:table.cell>
                        <flux:table.cell>{{ $admin->role?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$admin->is_active ? 'green' : 'zinc'" size="sm">
                                {{ $admin->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" wire:click="edit('{{ $admin->id }}')">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" wire:click="toggleActive('{{ $admin->id }}')">
                                    {{ $admin->is_active ? __('Deactivate') : __('Activate') }}
                                </flux:button>
                                <flux:button size="sm" variant="danger" wire:click="delete('{{ $admin->id }}')" wire:confirm="{{ __('Delete this admin?') }}">
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">{{ __('No platform admins yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{ $this->admins->links() }}
    </div>

    <flux:modal wire:model.self="showModal" class="md:w-96">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Admin') : __('New Admin') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="email" :label="__('Email')" type="email" required />
            <flux:input wire:model="password" :label="__('Password')" type="password" :placeholder="$editingId ? __('Leave blank to keep current password') : ''" viewable />

            <flux:select wire:model="role_id" :label="__('Role')" placeholder="{{ __('Select a role') }}">
                @foreach ($this->roles as $role)
                    <flux:select.option value="{{ $role->id }}">{{ $role->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
