<div class="flex flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Leads') }}</flux:heading>
        <flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export CSV') }}</flux:button>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
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

        <flux:select wire:model.live="sourceType" placeholder="{{ __('All sources') }}">
            <flux:select.option value="">{{ __('All sources') }}</flux:select.option>
            @foreach ($this->sourceTypeOptions() as $option)
                <flux:select.option value="{{ $option }}">{{ ucfirst(str_replace('_', ' ', $option)) }}</flux:select.option>
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

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Source') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Priority') }}</flux:table.column>
            <flux:table.column>{{ __('Assigned To') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->leads as $lead)
                <flux:table.row wire:key="lead-{{ $lead->id }}">
                    <flux:table.cell>{{ $lead->name }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" icon="{{ $lead->source->type->icon() }}">{{ $lead->source->type->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell><flux:badge size="sm">{{ ucfirst(str_replace('_', ' ', $lead->status->value)) }}</flux:badge></flux:table.cell>
                    <flux:table.cell>{{ ucfirst($lead->priority->value) }}</flux:table.cell>
                    <flux:table.cell>{{ $lead->assignedUser?->name ?? __('Unassigned') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="sm" :href="route('tenant-leads.show', $lead)" wire:navigate>{{ __('View') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('No leads yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{ $this->leads->links() }}
</div>
