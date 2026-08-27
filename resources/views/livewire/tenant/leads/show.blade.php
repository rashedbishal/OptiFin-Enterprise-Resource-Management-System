<div class="flex flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ $lead->name }}</flux:heading>
            <flux:text>{{ $lead->email }} {{ $lead->phone ? '· '.$lead->phone : '' }}</flux:text>
        </div>
        @if ($this->isClientAdmin)
            <flux:button variant="danger" size="sm" wire:click="delete" wire:confirm="{{ __('Delete this lead?') }}">
                {{ __('Delete') }}
            </flux:button>
        @endif
    </div>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="flex flex-col gap-6 md:col-span-2">
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Details') }}</flux:heading>
                <flux:text class="mb-2">
                    <flux:badge size="sm" icon="{{ $lead->source->type->icon() }}">{{ $lead->source->type->label() }}</flux:badge>
                    {{ $lead->source->name }}
                </flux:text>
                @if ($lead->message)
                    <flux:text class="whitespace-pre-line">{{ $lead->message }}</flux:text>
                @endif
            </flux:card>

            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Activity') }}</flux:heading>
                <div class="flex flex-col gap-4">
                    @forelse ($this->activity as $event)
                        <div class="border-b border-neutral-200 pb-3 last:border-0 dark:border-neutral-700">
                            <flux:text class="text-xs text-zinc-500">{{ $event['created_at']?->diffForHumans() }} · {{ $event['user'] ?? __('System') }}</flux:text>
                            @if ($event['type'] === 'status_change')
                                <flux:text>{{ __('Status changed to :status', ['status' => ucfirst(str_replace('_', ' ', $event['new_status']))]) }}</flux:text>
                            @elseif ($event['type'] === 'assignment')
                                <flux:text>{{ __('Assigned to :name', ['name' => $event['assigned_to']]) }}</flux:text>
                            @else
                                <flux:text>{{ $event['note'] }}</flux:text>
                            @endif
                            @if (! empty($event['note']) && $event['type'] !== 'note')
                                <flux:text class="text-xs italic">{{ $event['note'] }}</flux:text>
                            @endif
                        </div>
                    @empty
                        <flux:text>{{ __('No activity yet.') }}</flux:text>
                    @endforelse
                </div>
            </flux:card>

            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Add Note') }}</flux:heading>
                <form wire:submit="addNote" class="flex flex-col gap-3">
                    <flux:textarea wire:model="newNote" :label="__('Note')" />
                    <div>
                        <flux:button type="submit" variant="primary" size="sm">{{ __('Add Note') }}</flux:button>
                    </div>
                </form>
            </flux:card>
        </div>

        <div class="flex flex-col gap-6">
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Status') }}</flux:heading>
                <form wire:submit="updateStatus" class="flex flex-col gap-3">
                    <flux:select wire:model="status" :label="__('Status')">
                        @foreach ($this->statusOptions() as $option)
                            <flux:select.option value="{{ $option }}">{{ ucfirst(str_replace('_', ' ', $option)) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="priority" :label="__('Priority')">
                        @foreach ($this->priorityOptions() as $option)
                            <flux:select.option value="{{ $option }}">{{ ucfirst($option) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" variant="primary" size="sm">{{ __('Update') }}</flux:button>
                </form>
            </flux:card>

            @if ($this->isClientAdmin)
                <flux:card>
                    <flux:heading size="lg" class="mb-4">{{ __('Assignment') }}</flux:heading>
                    <form wire:submit="assign" class="flex flex-col gap-3">
                        <flux:select wire:model="assigneeId" :label="__('Assigned To')" placeholder="{{ __('Unassigned') }}">
                            @foreach ($this->assignableUsers as $assignableUser)
                                <flux:select.option value="{{ $assignableUser->id }}">{{ $assignableUser->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="submit" variant="primary" size="sm">{{ __('Assign') }}</flux:button>
                    </form>
                </flux:card>
            @endif
        </div>
    </div>
</div>
