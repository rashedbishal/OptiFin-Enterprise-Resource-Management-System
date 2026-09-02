<flux:card>
    <flux:heading size="lg" class="mb-4">{{ __('Activity') }}</flux:heading>
    <div class="flex max-h-96 flex-col gap-4 overflow-y-auto pe-1">
        @forelse ($activity as $event)
            <div class="border-b border-neutral-200 pb-3 last:border-0 dark:border-neutral-700">
                <flux:text class="text-xs text-zinc-500">{{ $event['created_at']?->diffForHumans() }} · {{ $event['user'] ?? __('System') }}</flux:text>
                @if ($event['type'] === 'status_change')
                    <flux:text>{{ __('Status changed to :status', ['status' => ucfirst(str_replace('_', ' ', $event['new_status']))]) }}</flux:text>
                @elseif ($event['type'] === 'assignment')
                    <flux:text>{{ __('Assigned to :name', ['name' => $event['assigned_to']]) }}</flux:text>
                @elseif ($event['type'] === 'details_updated')
                    <flux:text>{{ __('Updated: :fields', ['fields' => implode(', ', $event['fields'])]) }}</flux:text>
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
