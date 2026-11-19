@push('breadcrumbs')
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Users')],
    ]" />
@endpush

<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
        <div class="flex items-center justify-between">
            <flux:heading size="xl" class="hidden lg:block">{{ __('Users') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New User') }}</flux:button>
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
                @forelse ($this->users as $user)
                    <flux:table.row wire:key="tenant-user-{{ $user->id }}">
                        <flux:table.cell>{{ $user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $user->role?->name ?? '—' }}
                            @if ($user->is_whatsapp_backup_receiver)
                                <flux:tooltip content="{{ __('Can receive WhatsApp calls for other assigned users') }}">
                                    <flux:badge size="sm" color="indigo">{{ __('Backup Calls') }}</flux:badge>
                                </flux:tooltip>
                            @endif
                            @if ($user->callingAppDevice)
                                <flux:tooltip content="{{ __('Calling app connected on :device', ['device' => $user->callingAppDevice->device_name ?? __('a phone')]) }}">
                                    <flux:badge size="sm" color="green" icon="device-phone-mobile">{{ __('Calling App') }}</flux:badge>
                                </flux:tooltip>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$user->is_active ? 'green' : 'zinc'" size="sm">
                                {{ $user->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                @if ($user->id === auth()->id())
                                    <flux:tooltip content="{{ __('Your own account is provisioned by Platform Admin — only Platform Admin can edit, deactivate, or delete it.') }}">
                                        <flux:badge size="sm">{{ __('You') }}</flux:badge>
                                    </flux:tooltip>
                                @else
                                    <flux:button size="sm" wire:click="edit('{{ $user->id }}')">{{ __('Edit') }}</flux:button>
                                    <flux:button size="sm" wire:click="toggleActive('{{ $user->id }}')">
                                        {{ $user->is_active ? __('Deactivate') : __('Activate') }}
                                    </flux:button>
                                    @if ($user->user_type !== \App\Enums\TenantUserType::ClientAdmin && ! $this->receivesUnassignedWhatsAppLeads($user))
                                        <flux:tooltip content="{{ __('Allows this user to receive eligible incoming WhatsApp calls when the assigned user is unavailable or already handling another call. It does not change lead assignment or grant outbound calling access.') }}">
                                            <flux:button size="sm" wire:click="toggleBackupReceiver('{{ $user->id }}')">
                                                {{ $user->is_whatsapp_backup_receiver ? __('Disable Backup Calls') : __('Enable Backup Calls') }}
                                            </flux:button>
                                        </flux:tooltip>
                                    @endif
                                    @if ($user->callingAppDevice)
                                        <flux:button size="sm" wire:click="confirmUnpairCallingApp('{{ $user->id }}')">{{ __('Unpair App') }}</flux:button>
                                    @endif
                                    <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $user->id }}')">
                                        {{ __('Delete') }}
                                    </flux:button>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">{{ __('No users yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{ $this->users->links() }}

    <flux:modal wire:model.self="showModal" scroll="body" class="md:w-96">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit User') : __('New User') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="email" :label="__('Email')" type="email" required />
            <flux:input wire:model="password" :label="__('Password')" type="password" :placeholder="$editingId ? __('Leave blank to keep current password') : ''" viewable />

            @php
                $roleOpts = array_merge(
                    [['value' => '', 'label' => __('Select a role')]],
                    collect($this->roles)->map(fn($r) => ['value' => (string) $r->id, 'label' => $r->name])->toArray()
                );
            @endphp
            <x-dropdown-select wire:model="role_id" :label="__('Role')" :placeholder="__('Select a role')" :options="$roleOpts" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this user?')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />

    <x-confirm-modal
        wire:model.self="showUnpairCallingAppModal"
        :title="__('Unpair this user\'s calling app?')"
        :description="__('Their phone will stop receiving and placing calls through the app until they connect it again from Settings → Calling app.')"
        confirm-action="unpairCallingAppConfirmed"
        :confirm-label="__('Unpair')"
    />
</div>
