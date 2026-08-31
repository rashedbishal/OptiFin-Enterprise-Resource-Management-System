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

    <flux:modal wire:model.self="showModal" class="md:w-[42rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Role') : __('New Role') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />

            <flux:field>
                <flux:label>{{ __('Modules') }}</flux:label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($this->modules as $module)
                        <flux:checkbox wire:model.live="moduleIds" value="{{ $module->id }}" :label="$module->name" />
                    @endforeach
                </div>
            </flux:field>

            @if (! empty($moduleIds))
                <flux:field>
                    <flux:label>{{ __('Permissions per module') }}</flux:label>
                    <flux:description>{{ __('Choose which actions this role can perform within each selected module.') }}</flux:description>
                    <div class="flex flex-col gap-3">
                        @foreach ($this->modules->whereIn('id', $moduleIds) as $module)
                            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="platform-module-permissions-{{ $module->id }}">
                                <flux:heading size="sm" class="mb-2">{{ $module->name }}</flux:heading>
                                <div class="flex flex-wrap gap-4">
                                    @foreach ($this->permissions as $permission)
                                        <flux:checkbox
                                            wire:model="modulePermissions.{{ $module->id }}"
                                            value="{{ $permission->id }}"
                                            :label="$permission->name"
                                        />
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:field>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
