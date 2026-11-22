@php
    $actionColor = fn (?string $event): string => match ($event) {
        'created', 'published' => 'green',
        'updated', 'saved' => 'blue',
        'deleted', 'removed', 'backup deleted' => 'red',
        'history deleted' => 'yellow',
        default => 'zinc',
    };
    $hasFilters = $client !== '' || $kind !== '' || $action !== '' || $who !== '' || $from !== '' || $to !== '';
@endphp

<div class="flex flex-1 flex-col gap-6">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
        ['label' => __('Settings')],
        ['label' => __('Activity log')],
    ]" />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" class="hidden lg:block">{{ __('Activity log') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Who changed which admin, role, client, client user or sensitive setting — and when. Passwords and keys are never recorded.') }}</flux:text>
        </div>
        <flux:button icon="trash" wire:click="openDeleteModal" data-test="delete-older">{{ __('Delete old entries') }}</flux:button>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <x-dropdown-select wire:model.live="client" :label="__('Client')" :placeholder="__('All clients')" :options="array_merge([['value' => '', 'label' => __('All clients')]], $this->clientOptions)" />
        <x-dropdown-select wire:model.live="kind" :label="__('What')" :placeholder="__('Everything')" :options="array_merge([['value' => '', 'label' => __('Everything')]], $this->kindOptions())" />
        <x-dropdown-select wire:model.live="action" :label="__('Action')" :placeholder="__('All actions')" :options="array_merge([['value' => '', 'label' => __('All actions')]], $this->actionOptions())" />
        <x-dropdown-select wire:model.live="who" :label="__('Who')" :placeholder="__('Anyone')" :options="array_merge([['value' => '', 'label' => __('Anyone')]], $this->whoOptions)" />
        <flux:input type="date" wire:model.live="from" :label="__('From')" />
        <flux:input type="date" wire:model.live="to" :label="__('To')" />
    </div>

    @if ($hasFilters)
        <div>
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        </div>
    @endif

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('When') }}</flux:table.column>
                <flux:table.column>{{ __('Who') }}</flux:table.column>
                <flux:table.column>{{ __('Action') }}</flux:table.column>
                <flux:table.column>{{ __('What') }}</flux:table.column>
                <flux:table.column>{{ __('Changes') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->entries as $entry)
                    @php($described = $this->describe($entry))
                    <flux:table.row wire:key="activity-{{ $entry->id }}" data-test="activity-row">
                        <flux:table.cell class="whitespace-nowrap align-top">
                            <div class="text-zinc-800 dark:text-white">{{ $entry->created_at?->toDayDateTimeString() }}</div>
                            <div class="text-xs text-zinc-500">{{ $entry->created_at?->diffForHumans() }}</div>
                        </flux:table.cell>
                        <flux:table.cell class="align-top">
                            <div class="font-medium text-zinc-800 dark:text-white">{{ $described['who'] }}</div>
                            @if ($described['whoKind'])
                                <div class="text-xs text-zinc-500">{{ $described['whoKind'] }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="align-top">
                            <flux:badge size="sm" :color="$actionColor($entry->event)">{{ Str::ucfirst((string) ($entry->event ?? $entry->description)) }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="max-w-xs align-top whitespace-normal">{{ $described['what'] }}</flux:table.cell>
                        <flux:table.cell class="max-w-md align-top whitespace-normal">
                            @forelse ($described['changes'] as $change)
                                <div class="text-sm">
                                    <span class="text-zinc-500">{{ Str::headline($change['field']) }}:</span>
                                    @if ($change['old'] !== null && $change['new'] !== null)
                                        <span class="line-through decoration-zinc-400">{{ $change['old'] }}</span> → <span class="font-medium">{{ $change['new'] }}</span>
                                    @else
                                        <span class="font-medium">{{ $change['new'] ?? $change['old'] }}</span>
                                    @endif
                                </div>
                            @empty
                                <span class="text-zinc-400">—</span>
                            @endforelse
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">{{ $hasFilters ? __('No entries match these filters.') : __('Nothing has been recorded yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    {{ $this->entries->links() }}

    <flux:modal wire:model.self="showDeleteModal" class="md:w-[28rem]">
        <form wire:submit="deleteOlder" class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Delete old entries') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Deletes every entry older than the date you choose. Records of earlier deletions are always kept, and this deletion is recorded too.') }}</flux:text>
            </div>

            <flux:input type="date" wire:model.live="deleteBefore" :label="__('Delete entries older than')" />

            @if ($this->deleteCount !== null)
                <flux:callout :variant="$this->deleteCount > 0 ? 'warning' : 'secondary'" icon="exclamation-triangle">
                    <flux:callout.text>
                        {{ $this->deleteCount > 0
                            ? trans_choice(':count entry will be permanently deleted.|:count entries will be permanently deleted.', $this->deleteCount)
                            : __('No entries are older than this date.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            <flux:input type="password" wire:model="password" :label="__('Your password')" autocomplete="current-password" viewable />

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="$set('showDeleteModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="deleteOlder">{{ __('Delete') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
