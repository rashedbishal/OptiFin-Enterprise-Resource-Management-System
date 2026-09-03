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
                <flux:heading size="xl" class="hidden lg:block">{{ __('Lead Sources') }}</flux:heading>
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
                <flux:text variant="subtle">{{ __('Click a status to add it to this client\'s pipeline — the click order becomes its position. Click a selected status again to remove it (you\'ll be asked to confirm). A status with leads in it (shown below its name) can\'t be removed until those leads are reassigned.') }}</flux:text>
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach ($this->allLeadStatuses as $status)
                    @php
                        $position = $this->selectedLeadStatuses->search(fn ($selected) => $selected->id === $status->id);
                        $leadCount = $this->leadCountsByStatus[$status->id] ?? 0;
                    @endphp
                    @if ($position !== false)
                        {{-- Selected: removing it is a mutating, hard-to-undo-cleanly
                        action (re-adding appends at the end rather than restoring the
                        original order), so it's the only branch that confirms —
                        confirmRemoveStatus() opens the shared removal modal below,
                        the add case below just toggles immediately. --}}
                        <flux:button
                            size="sm"
                            variant="primary"
                            :color="$status->color"
                            wire:click="confirmRemoveStatus('{{ $status->id }}')"
                        >
                            <span class="mr-1 font-semibold">{{ $position + 1 }}.</span>
                            {{ $status->name }}
                            @if ($leadCount > 0)
                                <span class="ml-1 text-xs opacity-75">({{ trans_choice(':count lead|:count leads', $leadCount, ['count' => $leadCount]) }})</span>
                            @endif
                        </flux:button>
                    @else
                        <flux:button
                            size="sm"
                            variant="ghost"
                            wire:click="toggleLeadStatus('{{ $status->id }}')"
                        >
                            {{ $status->name }}
                        </flux:button>
                    @endif
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

        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Preferred Currencies') }}</flux:heading>
                <flux:text variant="subtle">{{ __('Which currencies this client can pick from for a lead\'s Est. Budget / Deal Value. At least one must stay enabled — a client with none can\'t have a new Lead Source created, and existing leads would have no currency to offer.') }}</flux:text>
            </div>

            <div class="flex flex-wrap gap-4">
                @foreach ($this->allCurrencies as $currency)
                    @php $enabled = $this->selectedCurrencies->contains('id', $currency->id); @endphp
                    <flux:checkbox
                        :checked="$enabled"
                        :label="$currency->label()"
                        wire:click="toggleCurrency('{{ $currency->id }}')"
                    />
                @endforeach
            </div>
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
                                <flux:button size="sm" variant="danger" wire:click="confirmDelete('{{ $source->id }}')">
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

    <flux:modal wire:model.self="showModal" scroll="body" class="w-full md:max-w-[36rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit Lead Source') : __('New Lead Source') }}</flux:heading>

            @if ($editingId)
                <flux:text>{{ __('Type') }}: {{ $this->selectedType?->label() }}</flux:text>
            @else
                @php
                    $sourceTypeOpts = array_merge(
                        [['value' => '', 'label' => __('Select a type')]],
                        collect($this->typeOptions)->map(fn($o) => ['value' => $o->value, 'label' => $o->label()])->toArray()
                    );
                @endphp
                <x-dropdown-select wire:model.live="type" :label="__('Type')" :placeholder="__('Select a type')" :options="$sourceTypeOpts" />
            @endif

            <flux:input wire:model="name" :label="__('Name')" required />

            @if ($this->selectedType)
                @foreach ($this->selectedType->configFields() as $field)
                    @if ($field['type'] === 'select')
                        @php
                            $fieldOpts = array_merge(
                                [['value' => '', 'label' => __('Select an option')]],
                                collect($field['options'])->map(fn($lbl, $val) => ['value' => (string) $val, 'label' => (string) $lbl])->values()->toArray()
                            );
                        @endphp
                        <x-dropdown-select wire:key="config-field-{{ $field['key'] }}" wire:model.live="config.{{ $field['key'] }}" :label="$field['label']" :options="$fieldOpts" />
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

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this lead source?')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />

    <x-confirm-modal
        wire:model.self="showRemoveStatusModal"
        :title="__('Remove :name from this client\'s pipeline?', ['name' => $this->allLeadStatuses->firstWhere('id', $confirmingRemoveStatusId)?->name ?? ''])"
        confirm-action="removeStatusConfirmed"
        :confirm-label="__('Remove')"
    />
</div>
