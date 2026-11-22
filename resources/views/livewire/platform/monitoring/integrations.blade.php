@php
    $counts = $this->counts;
@endphp

<div class="flex flex-1 flex-col gap-6">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
        ['label' => __('Monitoring')],
        ['label' => __('Integrations')],
    ]" />

    <div>
        <flux:heading size="xl">{{ __('Integrations') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Every client\'s lead sources and whether leads are still arriving. Meta tokens are checked once a day (03:00 Bangladesh time).') }}</flux:text>
    </div>

    <div class="grid auto-rows-min gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text>{{ __('Lead sources') }}</flux:text>
            <flux:heading size="xl">{{ $counts['total'] }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Need fixing') }}</flux:text>
            <flux:heading size="xl" @class(['text-red-600 dark:text-red-400' => $counts['serious'] > 0])>{{ $counts['serious'] }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Worth a look') }}</flux:text>
            <flux:heading size="xl" @class(['text-yellow-600 dark:text-yellow-400' => $counts['warnings'] > 0])>{{ $counts['warnings'] }}</flux:heading>
        </flux:card>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <div class="flex gap-2" role="group" aria-label="{{ __('Show') }}">
            <flux:button size="sm" :variant="$problemsOnly ? 'primary' : 'outline'" wire:click="$set('problemsOnly', true)">{{ __('Problems only') }}</flux:button>
            <flux:button size="sm" :variant="$problemsOnly ? 'outline' : 'primary'" wire:click="$set('problemsOnly', false)">{{ __('All sources') }}</flux:button>
        </div>
        <div class="w-full sm:w-72">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search client or source')" clearable />
        </div>
    </div>

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Client / source') }}</flux:table.column>
                <flux:table.column>{{ __('Last activity') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Leads 7d / 30d') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column align="end"></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->rows as $row)
                    @php($source = $row['source'])
                    <flux:table.row wire:key="integration-{{ $source->id }}" data-test="integration-row">
                        <flux:table.cell class="max-w-xs">
                            <div class="font-medium text-zinc-800 dark:text-white">{{ $source->tenant->name }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $source->name }} · {{ $source->type->label() }}</div>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $row['lastActivityAt']?->diffForHumans() ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="end" class="whitespace-nowrap">{{ $row['leads7'] }} / {{ $row['leads30'] }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @forelse ($row['problems'] as $problem)
                                    <flux:tooltip :content="$problem['detail']">
                                        <flux:badge size="sm" :color="$problem['problem']->color()">{{ $problem['problem']->label() }}</flux:badge>
                                    </flux:tooltip>
                                @empty
                                    <flux:badge size="sm" :color="$row['info'] ? 'zinc' : 'green'">{{ $row['info'] ?? __('OK') }}</flux:badge>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('admin.clients.lead-sources', $source->tenant)" wire:navigate>{{ __('Open') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            {{ $problemsOnly && $search === '' ? __('No problems found — every lead source looks healthy.') : __('No lead sources match.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
