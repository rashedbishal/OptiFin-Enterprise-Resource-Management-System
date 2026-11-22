@php
    $summary = $this->summary;
    $periodOptions = [
        ['value' => '1_hour', 'label' => __('Last hour')],
        ['value' => '6_hours', 'label' => __('Last 6 hours')],
        ['value' => '24_hours', 'label' => __('Last 24 hours')],
        ['value' => '7_days', 'label' => __('Last 7 days')],
    ];
    $gigabytes = fn (int $megabytes): string => number_format($megabytes / 1024, 1).' GB';
@endphp

<div class="flex flex-1 flex-col gap-6" wire:poll.15s>
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
        ['label' => __('Monitoring')],
        ['label' => __('Pulse')],
    ]" />

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Pulse') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Application performance: slow requests, queries and jobs, exceptions and server load.') }}</flux:text>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-44">
                <x-dropdown-select wire:model.live="period" :options="$periodOptions" />
            </div>
            <flux:button :href="route('pulse')" target="_blank" icon-trailing="arrow-top-right-on-square">
                {{ __('Open Pulse dashboard') }}
            </flux:button>
        </div>
    </div>

    @if ($summary['error'])
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Can\'t read Pulse data')">
            <flux:callout.text>{{ $summary['error'] }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:card>
                <flux:text>{{ __('Slow requests') }}</flux:text>
                <flux:heading size="xl">{{ number_format($summary['slowRequestCount']) }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>{{ __('Exceptions') }}</flux:text>
                <flux:heading size="xl" @class(['text-red-600 dark:text-red-400' => $summary['exceptionCount'] > 0])>{{ number_format($summary['exceptionCount']) }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>{{ __('Failed jobs') }}</flux:text>
                <flux:heading size="xl" @class(['text-red-600 dark:text-red-400' => $summary['failedJobCount'] > 0])>{{ number_format($summary['failedJobCount']) }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>{{ __('Cache hit rate') }}</flux:text>
                <flux:heading size="xl">{{ $summary['cacheHitRate'] === null ? '—' : $summary['cacheHitRate'].'%' }}</flux:heading>
            </flux:card>
        </div>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Servers') }}</flux:heading>
            @if ($summary['servers'] === [])
                <flux:text>{{ __('No server has reported yet. Run `php artisan pulse:check` (Supervisor in production) to collect CPU, memory and disk usage.') }}</flux:text>
            @else
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($summary['servers'] as $server)
                        @php($memoryPercent = $server['memoryTotal'] > 0 ? (int) round($server['memoryUsed'] / $server['memoryTotal'] * 100) : 0)
                        <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="server-{{ $server['name'] }}">
                            <div class="flex items-center justify-between gap-2">
                                <flux:heading>{{ $server['name'] }}</flux:heading>
                                <flux:badge size="sm" :color="$server['recentlyReported'] ? 'green' : 'red'">
                                    {{ $server['recentlyReported'] ? __('Live') : __('Last seen :time', ['time' => $server['updatedAt']->diffForHumans()]) }}
                                </flux:badge>
                            </div>
                            <div>
                                <div class="flex justify-between text-sm"><span>{{ __('CPU') }}</span><span>{{ $server['cpu'] }}%</span></div>
                                <div class="mt-1 h-2 rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-2 rounded-full bg-accent" style="width: {{ min(100, $server['cpu']) }}%"></div></div>
                            </div>
                            <div>
                                <div class="flex justify-between text-sm"><span>{{ __('Memory') }}</span><span>{{ $gigabytes($server['memoryUsed']) }} / {{ $gigabytes($server['memoryTotal']) }}</span></div>
                                <div class="mt-1 h-2 rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-2 rounded-full bg-accent" style="width: {{ $memoryPercent }}%"></div></div>
                            </div>
                            @foreach ($server['storage'] as $disk)
                                @php($diskPercent = $disk['total'] > 0 ? (int) round($disk['used'] / $disk['total'] * 100) : 0)
                                <div>
                                    <div class="flex justify-between text-sm"><span>{{ __('Disk') }} <span class="text-zinc-500">{{ $disk['directory'] }}</span></span><span>{{ $gigabytes($disk['used']) }} / {{ $gigabytes($disk['total']) }}</span></div>
                                    <div class="mt-1 h-2 rounded-full bg-zinc-200 dark:bg-zinc-700"><div @class(['h-2 rounded-full', 'bg-red-500' => $diskPercent >= 90, 'bg-accent' => $diskPercent < 90]) style="width: {{ $diskPercent }}%"></div></div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>

        <div class="grid gap-6 xl:grid-cols-2">
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Slow requests') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Route') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Count') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Slowest') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($summary['slowRequests'] as $row)
                            <flux:table.row wire:key="slow-request-{{ $loop->index }}">
                                <flux:table.cell class="max-w-xs truncate"><flux:badge size="sm" class="me-2">{{ $row['method'] }}</flux:badge>{{ $row['uri'] }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($row['count']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap">{{ number_format((int) $row['slowest']) }} ms</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row><flux:table.cell colspan="3">{{ __('No slow requests in this period.') }}</flux:table.cell></flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Slow queries') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Query') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Count') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Slowest') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($summary['slowQueries'] as $row)
                            <flux:table.row wire:key="slow-query-{{ $loop->index }}">
                                <flux:table.cell class="max-w-xs">
                                    <div class="truncate font-mono text-xs" title="{{ $row['sql'] }}">{{ $row['sql'] }}</div>
                                    @if ($row['location'])
                                        <div class="truncate text-xs text-zinc-500">{{ $row['location'] }}</div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($row['count']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap">{{ number_format((int) $row['slowest']) }} ms</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row><flux:table.cell colspan="3">{{ __('No slow queries in this period.') }}</flux:table.cell></flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Exceptions') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Exception') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Count') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Latest') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($summary['exceptions'] as $row)
                            <flux:table.row wire:key="exception-{{ $loop->index }}">
                                <flux:table.cell class="max-w-xs">
                                    <div class="truncate font-medium">{{ class_basename($row['class']) }}</div>
                                    @if ($row['location'])
                                        <div class="truncate text-xs text-zinc-500">{{ $row['location'] }}</div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($row['count']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap">{{ $row['slowest'] ? \Carbon\CarbonImmutable::createFromTimestamp($row['slowest'])->diffForHumans() : '—' }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row><flux:table.cell colspan="3">{{ __('No exceptions in this period.') }}</flux:table.cell></flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Slow jobs') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Job') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Count') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Slowest') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($summary['slowJobs'] as $row)
                            <flux:table.row wire:key="slow-job-{{ $loop->index }}">
                                <flux:table.cell class="max-w-xs truncate font-medium" title="{{ $row['job'] }}">{{ class_basename($row['job']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($row['count']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap">{{ number_format((int) $row['slowest']) }} ms</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row><flux:table.cell colspan="3">{{ __('No slow jobs in this period.') }}</flux:table.cell></flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Queues') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Queue') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Queued') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Processed') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Released') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Failed') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($summary['queues'] as $row)
                            <flux:table.row wire:key="queue-{{ $row['queue'] }}">
                                <flux:table.cell class="font-medium">{{ $row['queue'] }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($row['queued']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($row['processed']) }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($row['released']) }}</flux:table.cell>
                                <flux:table.cell align="end" @class(['text-red-600 dark:text-red-400' => $row['failed'] > 0])>{{ number_format($row['failed']) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row><flux:table.cell colspan="5">{{ __('No queue activity in this period.') }}</flux:table.cell></flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Most active users') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Requests') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($summary['usage'] as $row)
                            <flux:table.row wire:key="usage-{{ $loop->index }}">
                                <flux:table.cell>
                                    <div class="font-medium">{{ $row['name'] }}</div>
                                    <div class="text-xs text-zinc-500">{{ $row['email'] }}</div>
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($row['count']) }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row><flux:table.cell colspan="2">{{ __('No user requests in this period.') }}</flux:table.cell></flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        </div>
    @endif
</div>
