<div class="flex flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8 overflow-y-auto min-h-0">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard')],
        ['label' => __('Leads'), 'href' => route('tenant-leads.index')],
        ['label' => $this->leadSource->name],
    ]" />

    <div class="flex items-center justify-between">
        <flux:heading size="xl" class="hidden lg:block">{{ $this->leadSource->name }}</flux:heading>
        <div class="flex gap-2">
            <flux:button icon="arrow-down-tray" wire:click="downloadXlsx">{{ __('Download XLSX') }}</flux:button>
            <flux:button :href="route('tenant-leads.index')" wire:navigate>{{ __('Back to Leads') }}</flux:button>
        </div>
    </div>

    @php
        $statusOpts = array_merge(
            [['value' => '', 'label' => __('All statuses')]],
            collect($this->statusOptions())->map(fn($o) => ['value' => $o, 'label' => ucfirst(str_replace('_', ' ', $o))])->toArray()
        );
        $priorityOpts = array_merge(
            [['value' => '', 'label' => __('All priorities')]],
            collect($this->priorityOptions())->map(fn($o) => ['value' => $o, 'label' => ucfirst($o)])->toArray()
        );
        if ($this->isClientAdmin) {
            $assignedToOpts = array_merge(
                [
                    ['value' => '', 'label' => __('All users')],
                    ['value' => 'unassigned', 'label' => __('Unassigned')],
                ],
                collect($this->assignableUsers)->map(fn($u) => ['value' => (string) $u->id, 'label' => $u->name])->toArray()
            );
        }
    @endphp

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
        <x-dropdown-select wire:model.live="status" :placeholder="__('All statuses')" :options="$statusOpts" icon="squares-2x2" />
        <x-dropdown-select wire:model.live="priority" :placeholder="__('All priorities')" :options="$priorityOpts" icon="funnel" />

        @if ($this->isClientAdmin)
            <x-dropdown-select wire:model.live="assignedTo" :placeholder="__('All users')" :options="$assignedToOpts" icon="user" />
        @endif
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Lead ID') }}</flux:table.column>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                @foreach ($this->fieldColumns as $column)
                    <flux:table.column>{{ ucwords(str_replace('_', ' ', $column)) }}</flux:table.column>
                @endforeach
                <flux:table.column>{{ __('Form') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Submitted') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->leads as $lead)
                    <flux:table.row wire:key="lead-{{ $lead->id }}">
                        <flux:table.cell><span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $lead->identifier }}</span></flux:table.cell>
                        <flux:table.cell>{{ $lead->name }}</flux:table.cell>
                        @php $fieldValues = $lead->leadAdFieldValues(); @endphp
                        @foreach ($this->fieldColumns as $column)
                            <flux:table.cell>{{ $fieldValues[$column] ?? '—' }}</flux:table.cell>
                        @endforeach
                        <flux:table.cell>{{ $lead->source_data['form_name'] ?? '—' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$lead->status->color()">{{ $lead->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $lead->created_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end">
                                <flux:button size="sm" :href="route('tenant-leads.show', $lead)" wire:navigate>{{ __('View') }}</flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="{{ count($this->fieldColumns) + 6 }}">{{ __('No leads yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{ $this->leads->links() }}
</div>
