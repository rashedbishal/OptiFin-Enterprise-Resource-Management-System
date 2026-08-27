<div class="flex flex-1 flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ $tenant->name }}</flux:heading>
                <flux:text>{{ $tenant->tenant_code }}</flux:text>
            </div>

            <div class="flex items-center gap-2">
                <flux:select wire:model="status" class="w-40">
                    @foreach (['active', 'inactive', 'suspended'] as $option)
                        <flux:select.option value="{{ $option }}">{{ ucfirst($option) }}</flux:select.option>
                    @endforeach
                </flux:select>
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
