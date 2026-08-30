<div class="flex flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ $this->leadSource->name }}</flux:heading>
        <div class="flex gap-2">
            <flux:button icon="arrow-down-tray" wire:click="downloadXlsx">{{ __('Download XLSX') }}</flux:button>
            <flux:button :href="route('tenant-leads.index')" wire:navigate>{{ __('Back to Leads') }}</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
        <flux:select wire:model.live="status" placeholder="{{ __('All statuses') }}">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach ($this->statusOptions() as $option)
                <flux:select.option value="{{ $option }}">{{ ucfirst(str_replace('_', ' ', $option)) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="priority" placeholder="{{ __('All priorities') }}">
            <flux:select.option value="">{{ __('All priorities') }}</flux:select.option>
            @foreach ($this->priorityOptions() as $option)
                <flux:select.option value="{{ $option }}">{{ ucfirst($option) }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->isClientAdmin)
            <flux:select wire:model.live="assignedTo" placeholder="{{ __('All users') }}">
                <flux:select.option value="">{{ __('All users') }}</flux:select.option>
                <flux:select.option value="unassigned">{{ __('Unassigned') }}</flux:select.option>
                @foreach ($this->assignableUsers as $assignableUser)
                    <flux:select.option value="{{ $assignableUser->id }}">{{ $assignableUser->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
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
                        <flux:table.cell>{{ $lead->name }}</flux:table.cell>
                        @php $fieldValues = $lead->leadAdFieldValues(); @endphp
                        @foreach ($this->fieldColumns as $column)
                            <flux:table.cell>{{ $fieldValues[$column] ?? '—' }}</flux:table.cell>
                        @endforeach
                        <flux:table.cell>{{ $lead->source_data['form_name'] ?? '—' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm">{{ ucfirst(str_replace('_', ' ', $lead->status->value)) }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $lead->created_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end">
                                <flux:button size="sm" :href="route('tenant-leads.show', $lead)" wire:navigate>{{ __('View') }}</flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="{{ count($this->fieldColumns) + 5 }}">{{ __('No leads yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{ $this->leads->links() }}
</div>
