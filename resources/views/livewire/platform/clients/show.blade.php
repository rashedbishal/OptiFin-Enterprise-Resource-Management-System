<div class="flex flex-1 flex-col gap-6">
        <x-breadcrumbs :items="[
            ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
            ['label' => __('Clients'), 'href' => route('admin.clients.index')],
            ['label' => $tenant->name],
        ]" />

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl" class="hidden lg:block">{{ $tenant->name }}</flux:heading>
                <flux:text>{{ $tenant->tenant_code }}</flux:text>
            </div>

            <div class="flex items-center gap-2">
                @php
                    $tenantStatusOpts = collect(['active', 'inactive', 'suspended'])->map(fn($s) => ['value' => $s, 'label' => ucfirst($s)])->toArray();
                @endphp
                <div class="w-40">
                    <x-dropdown-select wire:model="status" :options="$tenantStatusOpts" />
                </div>
                <flux:button size="sm" wire:click="updateStatus">{{ __('Update') }}</flux:button>
            </div>
        </div>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Assigned Modules') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                @foreach ($this->modules as $module)
                    <flux:checkbox
                        wire:click="toggleModule('{{ $module->id }}')"
                        :checked="in_array($module->id, $this->assignedModuleIds, true)"
                        :label="$module->name"
                    />
                @endforeach
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Configure Modules') }}</flux:heading>
            <flux:text class="mb-4">{{ __('Only assigned modules can be configured for this client.') }}</flux:text>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                @foreach ($this->modules as $module)
                    @php $configUrl = $this->moduleConfigUrl($module); @endphp
                    @if ($configUrl)
                        <flux:button :href="$configUrl" :disabled="! $this->isModuleAssigned($module)" wire:navigate>
                            {{ $module->name }}
                        </flux:button>
                    @else
                        <flux:button wire:click="moduleNotConfigurable" :disabled="! $this->isModuleAssigned($module)">
                            {{ $module->name }}
                        </flux:button>
                    @endif
                @endforeach
            </div>
        </flux:card>
    </div>
