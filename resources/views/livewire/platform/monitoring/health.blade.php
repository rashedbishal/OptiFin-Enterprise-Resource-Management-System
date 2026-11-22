@php
    $results = $this->results;
    $statusBadge = fn (string $status): array => match ($status) {
        'ok' => ['color' => 'green', 'label' => __('OK')],
        'warning' => ['color' => 'yellow', 'label' => __('Warning')],
        'failed' => ['color' => 'red', 'label' => __('Failed')],
        'crashed' => ['color' => 'red', 'label' => __('Crashed')],
        'skipped' => ['color' => 'zinc', 'label' => __('Skipped')],
        default => ['color' => 'zinc', 'label' => ucfirst($status)],
    };
@endphp

<div class="flex flex-1 flex-col gap-6" wire:poll.30s>
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
        ['label' => __('Monitoring')],
        ['label' => __('Health')],
    ]" />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Health') }}</flux:heading>
            <flux:text class="mt-1">
                @if ($results['finishedAt'])
                    {{ __('Last checked :time.', ['time' => $results['finishedAt']->diffForHumans()]) }}
                @endif
                {{ __('Checks run automatically every minute.') }}
            </flux:text>
        </div>
        @if ($this->canRunChecks)
            <flux:button variant="primary" icon="arrow-path" wire:click="runChecks" wire:loading.attr="disabled" wire:target="runChecks">
                <span wire:loading.remove wire:target="runChecks">{{ __('Run checks now') }}</span>
                <span wire:loading wire:target="runChecks">{{ __('Running…') }}</span>
            </flux:button>
        @endif
    </div>

    @if ($results['checks'] === [])
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('No results yet')">
            <flux:callout.text>{{ __('The scheduler hasn\'t run the health checks yet. They run every minute once `php artisan schedule:run` is active.') }}</flux:callout.text>
        </flux:callout>
    @else
        @if ($results['counts']['failed'] > 0)
            <flux:callout variant="danger" icon="x-circle" :heading="trans_choice(':count check is failing|:count checks are failing', $results['counts']['failed'])" />
        @elseif ($results['counts']['warning'] > 0)
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="trans_choice(':count check needs attention|:count checks need attention', $results['counts']['warning'])" />
        @else
            <flux:callout variant="success" icon="check-circle" :heading="__('Everything is healthy')" />
        @endif

        <div class="grid auto-rows-min gap-4 sm:grid-cols-3">
            <flux:card>
                <flux:text>{{ __('Passing') }}</flux:text>
                <flux:heading size="xl" class="text-green-600 dark:text-green-400">{{ $results['counts']['ok'] }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>{{ __('Warnings') }}</flux:text>
                <flux:heading size="xl" @class(['text-yellow-600 dark:text-yellow-400' => $results['counts']['warning'] > 0])>{{ $results['counts']['warning'] }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>{{ __('Failing') }}</flux:text>
                <flux:heading size="xl" @class(['text-red-600 dark:text-red-400' => $results['counts']['failed'] > 0])>{{ $results['counts']['failed'] }}</flux:heading>
            </flux:card>
        </div>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Checks') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Check') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Result') }}</flux:table.column>
                    <flux:table.column>{{ __('Details') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($results['checks'] as $check)
                        @php($badge = $statusBadge($check['status']))
                        <flux:table.row wire:key="check-{{ $check['name'] }}" data-test="health-check-row">
                            <flux:table.cell class="font-medium">{{ $check['label'] }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$badge['color']">{{ $badge['label'] }}</flux:badge></flux:table.cell>
                            <flux:table.cell>{{ $check['summary'] }}</flux:table.cell>
                            <flux:table.cell class="max-w-md whitespace-normal text-zinc-500">{{ $check['message'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
