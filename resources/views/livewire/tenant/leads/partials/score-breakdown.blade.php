{{-- Lead Scoring's "why is this lead scored X?" panel — deliberately
distinct from the hero card's "Win Probability" stat (a manually-entered
deal field, previously mislabeled "Lead Score" — see lead-hero.blade.php)
so the two numbers never read as the same concept. Shown on both the full
page and the Kanban flyout (App\Livewire\Tenant\Leads\Show::$panel),
compact mode just tightens spacing. Read-only on view: opening this page
never triggers a recompute (unlike the duplicate-detection banner above
it) — see Show::latestScoreLog()'s docblock. --}}
@php
    $compact ??= false;
    $score = $lead->lead_score;
    $scoreColor = match (true) {
        $score === null => 'zinc',
        $score >= 70 => 'emerald',
        $score >= 40 => 'amber',
        default => 'red',
    };
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <flux:heading size="{{ $compact ? 'sm' : 'lg' }}" class="font-semibold text-zinc-900 dark:text-white">{{ __('Lead Score') }}</flux:heading>
                @if ($score !== null)
                    <flux:badge size="sm" :color="$scoreColor" class="font-semibold">{{ $score >= 70 ? __('High') : ($score >= 40 ? __('Medium') : __('Low')) }}</flux:badge>
                @endif
            </div>
            <span class="mt-0.5 block text-xs sm:text-sm font-medium text-zinc-700 dark:text-zinc-300 leading-normal">{{ __('Calculated from your scoring rules.') }}</span>
        </div>

        @if ($this->canManageScoring)
            <div class="flex flex-wrap items-center gap-2">
                <flux:button size="sm" icon="adjustments-horizontal" :href="route('tenant-leads.scoring-rules')" wire:navigate class="font-semibold border border-zinc-300 dark:border-zinc-700 bg-white hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-2xs">
                    {{ __('Manage Rules') }}
                </flux:button>
                <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="recalculateScore" wire:loading.attr="disabled" wire:target="recalculateScore" class="font-semibold shadow-xs">
                    <span wire:loading.remove wire:target="recalculateScore">{{ __('Recalculate') }}</span>
                    <span wire:loading wire:target="recalculateScore">{{ __('Recalculating...') }}</span>
                </flux:button>
            </div>
        @endif
    </div>

    @if ($score === null)
        {{-- "Not scored" must never read as a real, low computed score —
        see the approved architecture's "NULL vs. real 0" distinction. --}}
        <div class="rounded-xl border border-dashed border-zinc-200 p-4 text-center dark:border-zinc-700/80">
            <span class="text-xs sm:text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Not scored — no active scoring rules yet.') }}</span>
        </div>
    @else
        <div class="flex flex-col gap-2 rounded-xl border border-zinc-200/80 bg-zinc-50/80 p-3.5 dark:border-zinc-700/80 dark:bg-zinc-900/70">
            <div class="flex items-baseline justify-between gap-2">
                <div class="flex items-baseline gap-1.5">
                    <span class="text-3xl font-bold tracking-tight text-purple-600 dark:text-purple-400">{{ $score }}</span>
                    <span class="text-xs sm:text-sm font-bold text-zinc-700 dark:text-zinc-300">/ 100</span>
                </div>
                <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ trans_choice(':count rule matched|:count rules matched', count($this->latestScoreLog?->breakdown ?? []), ['count' => count($this->latestScoreLog?->breakdown ?? [])]) }}</span>
            </div>

            <div class="h-2.5 w-full overflow-hidden rounded-full bg-zinc-200/90 dark:bg-zinc-700/80">
                <div
                    class="h-full rounded-full bg-gradient-to-r from-purple-500 to-indigo-600 transition-all duration-500"
                    style="width: {{ min(100, max(0, $score)) }}%"
                ></div>
            </div>
        </div>

        @if ($this->latestScoreLog && $this->latestScoreLog->breakdown !== [])
            <div class="flex flex-col gap-2">
                <span class="text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">{{ __('Rule Breakdown') }}</span>
                <div class="flex flex-col gap-2">
                    @foreach ($this->latestScoreLog->breakdown as $entry)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200/80 bg-white p-2.5 shadow-2xs dark:border-zinc-700/80 dark:bg-zinc-800/90">
                            <div class="flex flex-col min-w-0 flex-1">
                                <span class="font-semibold text-xs sm:text-sm text-zinc-900 dark:text-white truncate">
                                    {{ $entry['rule_name'] }}
                                </span>
                                <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300 truncate">
                                    {{ $entry['field'] }} {{ $entry['operator'] }} {{ is_array($entry['value']) ? implode(', ', $entry['value']) : $entry['value'] }}
                                </span>
                            </div>
                            <flux:badge size="sm" :color="$entry['points'] >= 0 ? 'emerald' : 'red'" class="shrink-0 font-bold">
                                {{ $entry['points'] >= 0 ? '+' : '' }}{{ $entry['points'] }}
                            </flux:badge>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <span class="text-xs sm:text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('No rules matched this lead.') }}</span>
        @endif
    @endif
</flux:card>
