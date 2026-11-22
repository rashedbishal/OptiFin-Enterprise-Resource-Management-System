@php
    use App\Enums\FailedJobAdvice;

    $counts = $this->adviceCounts;
    $suggestedDeleteCount = $counts[FailedJobAdvice::Delete->value] ?? 0;
    $filters = array_merge(
        [['value' => '', 'label' => __('All'), 'count' => $this->listedCount]],
        array_map(fn (FailedJobAdvice $advice): array => ['value' => $advice->value, 'label' => $advice->label(), 'count' => $counts[$advice->value] ?? 0], FailedJobAdvice::cases()),
    );
    $calloutVariant = fn (FailedJobAdvice $advice): string => match ($advice) {
        FailedJobAdvice::Retry => 'success',
        FailedJobAdvice::Developer => 'danger',
        FailedJobAdvice::Review => 'warning',
        FailedJobAdvice::Delete => 'secondary',
    };
@endphp

<div class="flex flex-1 flex-col gap-6">
    <x-breadcrumbs :items="[
        ['label' => __('Dashboard'), 'href' => route('admin.dashboard')],
        ['label' => __('Monitoring')],
        ['label' => __('Horizon'), 'href' => route('admin.monitoring.horizon')],
        ['label' => __('Failed jobs')],
    ]" />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Failed jobs') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Background tasks (sending messages, notifications, imports…) that stopped with an error. Each one has a suggestion and the reason for it.') }}</flux:text>
        </div>
        @if ($this->canManage && $suggestedDeleteCount > 0)
            <flux:button icon="trash" wire:click="confirmDeleteSuggested" data-test="delete-suggested">
                {{ trans_choice('Delete the :count job marked "Delete"|Delete all :count jobs marked "Delete"', $suggestedDeleteCount) }}
            </flux:button>
        @endif
    </div>

    <flux:callout icon="light-bulb" :heading="__('What should I do with a failed job?')">
        <flux:callout.text>
            <ul class="mt-1 list-disc space-y-1 ps-5">
                <li><strong>{{ __('Retry') }}</strong> — {{ __('the problem was temporary (network, a busy server). Retrying runs the task again.') }}</li>
                <li><strong>{{ __('Delete') }}</strong> — {{ __('the task can never succeed or no longer matters (the record is gone, Meta refused it, it is too old). Deleting only removes this error from the list; it changes nothing else.') }}</li>
                <li><strong>{{ __('Ask a developer') }}</strong> — {{ __('a bug in the code. Retrying will fail again until it is fixed. Open it and send the error to your developer.') }}</li>
                <li><strong>{{ __('Not sure') }}</strong> — {{ __('if it is recent and important, retry once; if it fails again, delete it and tell your developer.') }}</li>
            </ul>
            <p class="mt-2">{{ __('Leaving failed jobs here does no harm — they just sit in this list.') }}</p>
        </flux:callout.text>
    </flux:callout>

    <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Filter by suggestion') }}">
        @foreach ($filters as $filter)
            <flux:button size="sm" :variant="$advice === $filter['value'] ? 'primary' : 'outline'" wire:click="$set('advice', '{{ $filter['value'] }}')" wire:key="filter-{{ $filter['value'] ?: 'all' }}">
                {{ $filter['label'] }} <span class="ms-1 opacity-70">{{ $filter['count'] }}</span>
            </flux:button>
        @endforeach
    </div>

    @if ($this->totalFailed > \App\Services\FailedJobService::LIMIT)
        <flux:text class="text-sm">{{ __('Showing the newest :limit of :total failed jobs.', ['limit' => \App\Services\FailedJobService::LIMIT, 'total' => number_format($this->totalFailed)]) }}</flux:text>
    @endif

    <flux:card>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('What failed') }}</flux:table.column>
                <flux:table.column>{{ __('When') }}</flux:table.column>
                <flux:table.column>{{ __('Suggestion') }}</flux:table.column>
                <flux:table.column align="end"></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->jobs as $job)
                    <flux:table.row wire:key="failed-job-{{ $job['id'] }}" data-test="failed-job-row">
                        <flux:table.cell class="max-w-md">
                            <div class="font-medium text-zinc-800 dark:text-white">{{ $job['name'] }}</div>
                            <div class="truncate text-xs text-zinc-500" title="{{ $job['error'] }}">{{ $job['error'] }}</div>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">
                            <span title="{{ $job['failedAt']->toDayDateTimeString() }}">{{ $job['failedAt']->diffForHumans() }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$job['advice']->color()" :icon="$job['advice']->icon()">{{ $job['advice']->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="whitespace-nowrap">
                            <flux:button size="sm" variant="ghost" wire:click="showJob('{{ $job['id'] }}')">{{ __('Details') }}</flux:button>
                            @if ($this->canManage)
                                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="retry('{{ $job['id'] }}')" wire:loading.attr="disabled" wire:target="retry('{{ $job['id'] }}')" :aria-label="__('Retry')" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete('{{ $job['id'] }}')" :aria-label="__('Delete')" />
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            {{ $advice === '' ? __('No failed jobs. Everything that ran in the background finished successfully.') : __('No failed jobs with this suggestion.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    {{ $this->jobs->links() }}

    <flux:modal wire:model.self="showDetailModal" class="md:w-[42rem]">
        @if ($job = $this->selectedJob)
            <div class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ $job['name'] }}</flux:heading>
                    <flux:text class="mt-1 text-sm">
                        {{ __('Failed :time', ['time' => $job['failedAt']->diffForHumans()]) }} · {{ $job['failedAt']->toDayDateTimeString() }} · {{ __('Queue: :queue', ['queue' => $job['queue']]) }}
                    </flux:text>
                </div>

                <flux:callout :variant="$calloutVariant($job['advice'])" :icon="$job['advice']->icon()" :heading="__('Suggestion: :advice', ['advice' => $job['advice']->label()])">
                    <flux:callout.text>{{ $job['reason'] }}</flux:callout.text>
                </flux:callout>

                <div>
                    <flux:heading size="sm">{{ __('Error') }}</flux:heading>
                    <div class="mt-1 rounded-lg bg-zinc-50 p-3 text-sm break-words text-zinc-700 dark:bg-zinc-800/60 dark:text-zinc-200">{{ $job['error'] }}</div>
                </div>

                <details class="text-sm">
                    <summary class="cursor-pointer text-zinc-500">{{ __('Technical details (for your developer)') }}</summary>
                    <div class="mt-2 text-xs text-zinc-500">{{ $job['class'] }} · {{ $job['id'] }}</div>
                    <pre class="mt-2 max-h-72 overflow-auto rounded-lg bg-zinc-900 p-3 text-xs whitespace-pre-wrap text-zinc-100">{{ $job['details'] }}</pre>
                </details>

                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button wire:click="closeDetail">{{ __('Close') }}</flux:button>
                    @if ($this->canManage)
                        <flux:button variant="danger" icon="trash" wire:click="confirmDelete('{{ $job['id'] }}')">{{ __('Delete') }}</flux:button>
                        <flux:button variant="primary" icon="arrow-path" wire:click="retry('{{ $job['id'] }}')" wire:loading.attr="disabled" wire:target="retry">{{ __('Retry') }}</flux:button>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>

    <x-confirm-modal
        wire:model.self="showDeleteModal"
        :title="__('Delete this failed job?')"
        :description="__('This only removes the error from the list. The task will not run again.')"
        confirm-action="deleteConfirmed"
        :confirm-label="__('Delete')"
    />

    <x-confirm-modal
        wire:model.self="showDeleteSuggestedModal"
        :title="trans_choice('Delete :count failed job?|Delete :count failed jobs?', $suggestedDeleteCount)"
        :description="__('These are the jobs marked “Delete” — ones that can never succeed or no longer matter. This only removes them from the list.')"
        confirm-action="deleteSuggestedConfirmed"
        :confirm-label="__('Delete all')"
    />
</div>
