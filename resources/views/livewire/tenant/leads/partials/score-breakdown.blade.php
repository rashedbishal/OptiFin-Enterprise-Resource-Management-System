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
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <flux:heading size="{{ $compact ? 'sm' : 'lg' }}" class="font-semibold text-zinc-900 dark:text-white">{{ __('Lead Score') }}</flux:heading>
                @if ($score !== null)
                    <flux:badge size="sm" :color="$scoreColor">{{ $score >= 70 ? __('High') : ($score >= 40 ? __('Medium') : __('Low')) }}</flux:badge>
                @endif
            </div>
            <flux:text size="sm" variant="subtle" class="mt-0.5 block leading-normal">{{ __('Calculated from your scoring rules.') }}</flux:text>
        </div>

        @if ($this->canManageScoring)
            <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                <flux:button size="sm" variant="subtle" icon="adjustments-horizontal" :href="route('tenant-leads.scoring-rules')" wire:navigate class="flex-1 sm:flex-none">
                    {{ __('Manage Rules') }}
                </flux:button>
                <flux:button size="sm" icon="arrow-path" wire:click="recalculateScore" wire:loading.attr="disabled" wire:target="recalculateScore" class="flex-1 sm:flex-none">
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
            <flux:text variant="subtle" size="sm">{{ __('Not scored — no active scoring rules yet.') }}</flux:text>
        </div>
    @else
        <div class="flex flex-col gap-2 rounded-xl border border-zinc-200/70 bg-zinc-50/60 p-3.5 dark:border-zinc-700/70 dark:bg-zinc-900/50">
            <div class="flex items-baseline justify-between gap-2">
                <div class="flex items-baseline gap-1.5">
                    <span class="text-3xl font-bold tracking-tight text-purple-600 dark:text-purple-400">{{ $score }}</span>
                    <flux:text variant="subtle" size="sm" class="font-medium">/ 100</flux:text>
                </div>
                <flux:text size="xs" variant="subtle">{{ trans_choice(':count rule matched|:count rules matched', count($this->latestScoreLog?->breakdown ?? []), ['count' => count($this->latestScoreLog?->breakdown ?? [])]) }}</flux:text>
            </div>

            <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-700/60">
                <div
                    class="h-full rounded-full bg-gradient-to-r from-purple-500 to-indigo-600 transition-all duration-500"
                    style="width: {{ min(100, max(0, $score)) }}%"
                ></div>
            </div>
        </div>

        @if ($this->latestScoreLog && $this->latestScoreLog->breakdown !== [])
            <div class="flex flex-col gap-2">
                <flux:text size="xs" variant="subtle" class="font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Rule Breakdown') }}</flux:text>
                <div class="flex flex-col gap-2">
                    @foreach ($this->latestScoreLog->breakdown as $entry)
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1.5 sm:gap-3 rounded-lg border border-zinc-200/60 bg-white p-2.5 shadow-2xs dark:border-zinc-700/60 dark:bg-zinc-800/80">
                            <div class="flex flex-col min-w-0">
                                <span class="font-medium text-xs sm:text-sm text-zinc-900 dark:text-white truncate">
                                    {{ $entry['rule_name'] }}
                                </span>
                                <span class="text-[11px] text-zinc-500 dark:text-zinc-400 truncate">
                                    {{ $entry['field'] }} {{ $entry['operator'] }} {{ is_array($entry['value']) ? implode(', ', $entry['value']) : $entry['value'] }}
                                </span>
                            </div>
                            <flux:badge size="sm" :color="$entry['points'] >= 0 ? 'emerald' : 'red'" class="shrink-0 self-start sm:self-center">
                                {{ $entry['points'] >= 0 ? '+' : '' }}{{ $entry['points'] }}
                            </flux:badge>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <flux:text size="sm" variant="subtle">{{ __('No rules matched this lead.') }}</flux:text>
        @endif
    @endif
</flux:card>
