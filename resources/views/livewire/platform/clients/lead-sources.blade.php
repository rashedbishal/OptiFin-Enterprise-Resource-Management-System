<div>
    <div class="flex flex-1 flex-col gap-6">
        <x-breadcrumbs :items="[
            ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
            ['label' => __('Clients'), 'href' => route('admin.clients.index')],
            ['label' => $tenant->name, 'href' => route('admin.clients.show', $tenant)],
            ['label' => __('Lead Sources')],
        ]" />

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Lead Sources') }}</flux:heading>
                <flux:text>{{ $tenant->name }}</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:button :href="route('admin.clients.show', $tenant)" wire:navigate>{{ __('Back to Client') }}</flux:button>
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Source') }}</flux:button>
            </div>
        </div>

        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Lead Pipeline') }}</flux:heading>
                <flux:text variant="subtle">{{ __('Click a status to add it to this client\'s pipeline — the click order becomes its position. Click a selected status again to remove it.') }}</flux:text>
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach ($this->allLeadStatuses as $status)
                    @php $position = $this->selectedLeadStatuses->search(fn ($selected) => $selected->id === $status->id); @endphp
                    <flux:button
                        size="sm"
                        :variant="$position === false ? 'ghost' : 'primary'"
                        :color="$position === false ? null : $status->color"
                        wire:click="toggleLeadStatus('{{ $status->id }}')"
                    >
                        @if ($position !== false)
                            <span class="mr-1 font-semibold">{{ $position + 1 }}.</span>
                        @endif
                        {{ $status->name }}
                    </flux:button>
                @endforeach
            </div>

            @if ($this->selectedLeadStatuses->isNotEmpty())
                <div class="flex flex-col gap-1">
                    <flux:text size="sm" variant="strong">{{ __('Resulting pipeline order') }}</flux:text>
                    <flux:text size="sm" variant="subtle">
                        {{ $this->selectedLeadStatuses->map(fn ($status) => $status->name)->implode(' → ') }}
                    </flux:text>
                </div>
            @endif
        </flux:card>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Webhook URL') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->leadSources as $source)
                    <flux:table.row wire:key="lead-source-{{ $source->id }}">
                        <flux:table.cell>{{ $source->name }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" icon="{{ $source->type->icon() }}">{{ $source->type->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>
                            @if ($url = $source->webhookUrl())
                                <flux:text class="font-mono text-xs">{{ $url }}</flux:text>
                            @else
                                <flux:text class="text-xs">{{ __('Polled every :min min', ['min' => $source->config['fetch_interval_minutes'] ?? 15]) }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$source->is_active ? 'green' : 'zinc'" size="sm">
                                {{ $source->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" wire:click="edit('{{ $source->id }}')">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" wire:click="testConnection('{{ $source->id }}')">{{ __('Test') }}</flux:button>
                                <flux:button size="sm" wire:click="toggleActive('{{ $source->id }}')">
                                    {{ $source->is_active ? __('Deactivate') : __('Activate') }}
                                </flux:button>
                                <flux:button size="sm" variant="danger" wire:click="delete('{{ $source->id }}')" wire:confirm="{{ __('Delete this lead source?') }}">
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">{{ __('No lead sources configured yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal wire:model.self="showModal" class="md:w-[32rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Lead Source') : __('New Lead Source') }}</flux:heading>

            @if ($editingId)
                <flux:text>{{ __('Type') }}: {{ $this->selectedType?->label() }}</flux:text>
            @else
                <flux:select wire:model.live="type" :label="__('Type')" placeholder="{{ __('Select a type') }}">
                    @foreach ($this->typeOptions as $option)
                        <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input wire:model="name" :label="__('Name')" required />

            @if ($this->selectedType)
                @foreach ($this->selectedType->configFields() as $field)
                    @if ($field['type'] === 'select')
                        <flux:select wire:key="config-field-{{ $field['key'] }}" wire:model.live="config.{{ $field['key'] }}" :label="$field['label']">
                            <flux:select.option value="">{{ __('Select an option') }}</flux:select.option>
                            @foreach ($field['options'] as $value => $optionLabel)
                                <flux:select.option value="{{ $value }}">{{ $optionLabel }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:input
                            wire:key="config-field-{{ $field['key'] }}"
                            wire:model="config.{{ $field['key'] }}"
                            :label="$field['label']"
                            type="{{ $field['type'] === 'password' ? 'password' : ($field['type'] === 'number' ? 'number' : ($field['type'] === 'email' ? 'email' : 'text')) }}"
                            :placeholder="$editingId && $field['sensitive'] ? __('Leave blank to keep current value') : ''"
                            :viewable="$field['sensitive']"
                            :disabled="isset($field['depends_on']) && empty($config[$field['depends_on']] ?? null)"
                        />
                    @endif
                @endforeach
            @endif

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
