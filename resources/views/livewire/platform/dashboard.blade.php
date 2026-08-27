<div class="flex flex-1 flex-col gap-6">
        <div class="grid auto-rows-min gap-4 md:grid-cols-4">
            <flux:card>
                <flux:text>{{ __('Total Clients') }}</flux:text>
                <flux:heading size="xl">{{ $this->totalClients }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>{{ __('Active Clients') }}</flux:text>
                <flux:heading size="xl">{{ $this->activeClients }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>{{ __('Platform Admins') }}</flux:text>
                <flux:heading size="xl">{{ $this->totalPlatformAdmins }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>{{ __('Available Modules') }}</flux:text>
                <flux:heading size="xl">{{ $this->availableModules }}</flux:heading>
            </flux:card>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Recently Created Clients') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Tenant Code') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->recentClients as $client)
                            <flux:table.row wire:key="recent-client-{{ $client->id }}">
                                <flux:table.cell>{{ $client->name }}</flux:table.cell>
                                <flux:table.cell>{{ $client->tenant_code }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm">{{ $client->status->value }}</flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3">{{ __('No clients yet.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Recently Created Admins') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Email') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->recentAdmins as $admin)
                            <flux:table.row wire:key="recent-admin-{{ $admin->id }}">
                                <flux:table.cell>{{ $admin->name }}</flux:table.cell>
                                <flux:table.cell>{{ $admin->email }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="2">{{ __('No platform admins yet.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>
    </div>
