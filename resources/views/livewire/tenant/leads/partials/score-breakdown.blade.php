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
@endphp

<flux:card>
    <div class="mb-3 flex items-start justify-between gap-3">
        <div>
            <flux:heading size="{{ $compact ? 'sm' : 'lg' }}">{{ __('Lead Score') }}</flux:heading>
            <flux:text size="sm" variant="subtle">{{ __('Calculated from your scoring rules.') }}</flux:text>
        </div>

        @if ($this->canManageScoring)
            <div class="flex shrink-0 items-center gap-2">
                <flux:button size="sm" variant="ghost" :href="route('tenant-leads.scoring-rules')" wire:navigate>
                    {{ __('Manage Rules') }}
                </flux:button>
                <flux:button size="sm" wire:click="recalculateScore" wire:loading.attr="disabled" wire:target="recalculateScore">
                    {{ __('Recalculate') }}
                </flux:button>
            </div>
        @endif
    </div>

    @if ($lead->lead_score === null)
        {{-- "Not scored" must never read as a real, low computed score —
        see the approved architecture's "NULL vs. real 0" distinction. --}}
        <flux:text variant="subtle">{{ __('Not scored — no active scoring rules yet.') }}</flux:text>
    @else
        <div class="mb-3 flex items-baseline gap-2">
            <flux:text class="text-3xl font-semibold text-purple-600 dark:text-purple-400">{{ $lead->lead_score }}</flux:text>
            <flux:text variant="subtle">/ 100</flux:text>
        </div>

        @if ($this->latestScoreLog && $this->latestScoreLog->breakdown !== [])
            <div class="flex flex-col gap-1.5">
                @foreach ($this->latestScoreLog->breakdown as $entry)
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span>
                            {{ $entry['rule_name'] }}
                            <span class="text-zinc-400 dark:text-zinc-500">
                                ({{ $entry['field'] }} {{ $entry['operator'] }} {{ is_array($entry['value']) ? implode(', ', $entry['value']) : $entry['value'] }})
                            </span>
                        </span>
                        <flux:badge size="sm" :color="$entry['points'] >= 0 ? 'emerald' : 'red'">
                            {{ $entry['points'] >= 0 ? '+' : '' }}{{ $entry['points'] }}
                        </flux:badge>
                    </div>
                @endforeach
            </div>
        @else
            <flux:text size="sm" variant="subtle">{{ __('No rules matched this lead.') }}</flux:text>
        @endif
    @endif
</flux:card>
