@php
    $snapshot = $this->snapshot;
    $statusBadge = match ($snapshot['status']) {
        'running' => ['color' => 'green', 'label' => __('Running')],
        'paused' => ['color' => 'yellow', 'label' => __('Paused')],
        default => ['color' => 'red', 'label' => __('Inactive')],
    };
@endphp

<div class="flex flex-1 flex-col gap-6" wire:poll.5s>
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
        ['label' => __('Monitoring')],
        ['label' => __('Horizon')],
    ]" />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="xl">{{ __('Horizon') }}</flux:heading>
                <flux:badge size="sm" :color="$statusBadge['color']" data-test="horizon-status">{{ $statusBadge['label'] }}</flux:badge>
            </div>
            <flux:text class="mt-1">{{ __('Queue workers, throughput and failed jobs. Updates every few seconds.') }}</flux:text>
        </div>
        <flux:button :href="route('horizon.index')" target="_blank" icon-trailing="arrow-top-right-on-square">
            {{ __('Open Horizon dashboard') }}
        </flux:button>
    </div>

    @if ($snapshot['error'])
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Can\'t read queue data')">
            <flux:callout.text>{{ $snapshot['error'] }}</flux:callout.text>
        </flux:callout>
    @elseif ($snapshot['status'] === 'inactive')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Horizon isn\'t running')">
            <flux:callout.text>{{ __('No queued job (notifications, imports, WhatsApp sends) is being processed. Start it with `php artisan horizon` (Supervisor in production).') }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card>
            <flux:text>{{ __('Jobs per minute') }}</flux:text>
            <flux:heading size="xl">{{ number_format($snapshot['jobsPerMinute']) }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Recent jobs') }}</flux:text>
            <flux:heading size="xl">{{ number_format($snapshot['recentJobs']) }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Failed jobs (recent)') }}</flux:text>
            <flux:heading size="xl" @class(['text-red-600 dark:text-red-400' => $snapshot['recentFailed'] > 0])>{{ number_format($snapshot['recentFailed']) }}</flux:heading>
        </flux:card>
        <flux:card>
            <flux:text>{{ __('Worker processes') }}</flux:text>
            <flux:heading size="xl">{{ number_format($snapshot['processes']) }}</flux:heading>
        </flux:card>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Current workload') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Queue') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Waiting') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Processes') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Clears in') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($snapshot['workload'] as $queue)
                        <flux:table.row wire:key="workload-{{ $queue['name'] }}">
                            <flux:table.cell class="font-medium">{{ $queue['name'] }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($queue['length']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $queue['processes'] }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $queue['wait'] > 0 ? __(':seconds s', ['seconds' => $queue['wait']]) : '—' }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4">{{ __('No queues are being watched.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Supervisors') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Supervisor') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Processes') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($snapshot['supervisors'] as $supervisor)
                        <flux:table.row wire:key="supervisor-{{ $supervisor['name'] }}">
                            <flux:table.cell class="font-medium">{{ $supervisor['name'] }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$supervisor['status'] === 'running' ? 'green' : 'yellow'">{{ ucfirst($supervisor['status']) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ $supervisor['processes'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3">{{ __('No supervisors are running.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:card>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <flux:heading size="lg">{{ __('Latest failed jobs') }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ trans_choice(':count failed job in total.|:count failed jobs in total.', $this->failedJobCount) }}</flux:text>
            </div>
            <flux:button :href="route('admin.monitoring.horizon.failed-jobs')" wire:navigate icon-trailing="arrow-right" data-test="review-failed-jobs">
                {{ __('Review failed jobs') }}
            </flux:button>
        </div>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('What failed') }}</flux:table.column>
                <flux:table.column>{{ __('When') }}</flux:table.column>
                <flux:table.column>{{ __('Suggestion') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->latestFailedJobs as $job)
                    <flux:table.row wire:key="failed-{{ $job['id'] }}">
                        <flux:table.cell class="max-w-md">
                            <div class="font-medium text-zinc-800 dark:text-white">{{ $job['name'] }}</div>
                            <div class="truncate text-xs text-zinc-500" title="{{ $job['error'] }}">{{ $job['error'] }}</div>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $job['failedAt']->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$job['advice']->color()" :icon="$job['advice']->icon()">{{ $job['advice']->label() }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3">{{ __('No failed jobs.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
