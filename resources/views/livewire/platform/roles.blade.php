<div>
    <div class="flex flex-1 flex-col gap-6">
        <div class="flex items-center justify-between">
            <flux:heading size="xl">{{ __('Platform Roles') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Role') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Modules') }}</flux:table.column>
                <flux:table.column>{{ __('Permissions') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->roles as $role)
                    <flux:table.row wire:key="role-{{ $role->id }}">
                        <flux:table.cell>{{ $role->name }}</flux:table.cell>
                        <flux:table.cell>{{ $role->modules->pluck('name')->join(', ') ?: '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @php $modulesById = $role->modules->keyBy('id'); @endphp
                            @if ($role->modulePermissions->isEmpty())
                                —
                            @else
                                <div class="flex flex-col gap-0.5">
                                    @foreach ($role->modulePermissions->groupBy('module_id') as $moduleId => $grants)
                                        <flux:text size="sm">
                                            <span class="font-medium">{{ $modulesById[$moduleId]?->name }}:</span>
                                            {{ $grants->pluck('permission.name')->join(', ') }}
                                        </flux:text>
                                    @endforeach
                                </div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" wire:click="edit('{{ $role->id }}')">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="delete('{{ $role->id }}')" wire:confirm="{{ __('Delete this role?') }}">
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">{{ __('No platform roles yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal wire:model.self="showModal" :dismissible="false" class="md:w-[42rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Role') : __('New Role') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />

            <flux:field>
                <flux:label>{{ __('Modules & Permissions') }}</flux:label>
                <flux:description>{{ __('Choose which actions this role can perform within each module. A module is only granted to the role once at least one permission is checked for it.') }}</flux:description>
                <flux:error name="modulePermissions" />

                <div class="flex flex-col gap-3">
                    @forelse ($this->modules as $module)
                        @php $granted = $modulePermissions[$module->id] ?? []; @endphp
                        <div
                            wire:key="platform-module-{{ $module->id }}"
                            class="rounded-lg border p-4 transition-colors {{ $granted ? 'border-green-300 dark:border-green-800' : 'border-zinc-200 dark:border-zinc-700' }}"
                        >
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <flux:heading size="sm">{{ $module->name }}</flux:heading>

                                <flux:badge size="sm" color="green" class="{{ $granted ? '' : 'invisible' }}">{{ __('Added') }}</flux:badge>
                            </div>

                            <flux:checkbox.group wire:model.live="modulePermissions.{{ $module->id }}" class="flex flex-wrap items-start gap-4">
                                @foreach ($this->permissions as $permission)
                                    <flux:checkbox
                                        wire:key="platform-module-{{ $module->id }}-permission-{{ $permission->id }}"
                                        value="{{ $permission->id }}"
                                        :label="$permission->name"
                                    />
                                @endforeach
                            </flux:checkbox.group>
                        </div>
                    @empty
                        <flux:text>{{ __('No modules available.') }}</flux:text>
                    @endforelse
                </div>
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
