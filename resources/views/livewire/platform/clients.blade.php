<div>
    <div class="flex flex-1 flex-col gap-6">
        <x-breadcrumbs :items="[
            ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
            ['label' => __('Clients')],
        ]" />

        <div class="flex items-center justify-between">
            <flux:heading size="xl" class="hidden lg:block">{{ __('Clients') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Client') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Tenant Code') }}</flux:table.column>
                <flux:table.column>{{ __('Users') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->clients as $client)
                    <flux:table.row wire:key="client-{{ $client->id }}">
                        <flux:table.cell>{{ $client->name }}</flux:table.cell>
                        <flux:table.cell>{{ $client->tenant_code }}</flux:table.cell>
                        <flux:table.cell>{{ $client->tenant_users_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$client->status->value === 'active' ? 'green' : 'zinc'" size="sm">
                                {{ ucfirst($client->status->value) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" :href="route('admin.clients.show', $client)" wire:navigate>
                                {{ __('Manage') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">{{ __('No clients yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{ $this->clients->links() }}
    </div>

    <flux:modal wire:model.self="showModal" class="md:w-[32rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ __('New Client') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Company name')" required />
            <flux:input wire:model="email" :label="__('Contact email')" type="email" />
            <flux:input wire:model="country" :label="__('Country')" />

            <flux:separator />

            <flux:heading size="sm">{{ __('Client Admin') }}</flux:heading>
            <flux:input wire:model="admin_name" :label="__('Name')" required />
            <flux:input wire:model="admin_email" :label="__('Email')" type="email" required />
            <flux:input wire:model="admin_password" :label="__('Password')" type="password" required viewable />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Create Client') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
