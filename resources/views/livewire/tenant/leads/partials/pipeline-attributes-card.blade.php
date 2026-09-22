@php
    $canUpdate ??= false;

    // See lead-hero.blade.php's identical pair for why this is purely a
    // visual reveal-together toggle, not a real query defer. Only ever
    // used inside show.blade.php's Overview tab (never the panel/flyout),
    // so this re-shimmers on every tab switch too, not just the very
    // first page load — see Show::$tabContentReady's docblock.
    $listLoadingTargets = 'switchTab,markTabContentReady';
    $showSkeletonByDefault = ! $tabContentReady;
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex items-center gap-2 border-b border-zinc-100 pb-3 dark:border-zinc-800">
        <div class="flex size-7 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400">
            <flux:icon.adjustments-horizontal variant="micro" class="size-4" />
        </div>
        <flux:heading size="lg" class="font-semibold">{{ __('Pipeline Attributes') }}</flux:heading>
    </div>

    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
    <div class="flex flex-col gap-2.5">
        {{-- Source Channel & Referred Via --}}
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#e4d6be] bg-[#f7f5f0] p-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center gap-2.5 min-w-0">
                <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <flux:icon.funnel variant="micro" class="size-4" />
                </div>
                <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Source Channel') }}</span>
            </div>
            <span class="inline-flex items-center rounded-lg bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-900 dark:bg-zinc-800 dark:text-white">
                {{ $lead->source->type->label() }}
            </span>
        </div>

        @if ($lead->industry)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#e4d6be] bg-[#f7f5f0] p-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-purple-50 text-purple-600 dark:bg-purple-950/60 dark:text-purple-400">
                        <flux:icon.briefcase variant="micro" class="size-4" />
                    </div>
                    <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Industry Sector') }}</span>
                </div>
                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white text-end break-words">{{ $lead->industry }}</span>
            </div>
        @endif

        @if ($isManual && $lead->lead_source)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#e4d6be] bg-[#f7f5f0] p-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                        <flux:icon.arrow-right-end-on-rectangle variant="micro" class="size-4" />
                    </div>
                    <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Referred Via') }}</span>
                </div>
                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white text-end break-words">{{ $lead->lead_source }}</span>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#e4d6be] bg-[#f7f5f0] p-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center gap-2.5 min-w-0">
                <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                    <flux:icon.user variant="micro" class="size-4" />
                </div>
                <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Assigned Sales Executive') }}</span>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white text-end">
                {{ $lead->assignedUser?->name ?? __('Unassigned') }}
            </span>
        </div>

        @if ($lead->estimated_budget !== null)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#e4d6be] bg-[#f7f5f0] p-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400">
                        <flux:icon.calculator variant="micro" class="size-4" />
                    </div>
                    <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Est. Budget') }}</span>
                </div>
                <span class="text-xs sm:text-sm font-bold text-zinc-900 dark:text-white">
                    {{ $lead->estimatedBudgetCurrency?->code }} {{ number_format((float) $lead->estimated_budget, 2) }}
                </span>
            </div>
        @endif

        @if ($lead->next_follow_up_at)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200/80 bg-amber-50/30 p-3 dark:border-amber-800/50 dark:bg-amber-950/20">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/60 dark:text-amber-300">
                        <flux:icon.clock variant="micro" class="size-4" />
                    </div>
                    <div>
                        <span class="block text-[11px] font-medium text-amber-700 dark:text-amber-400">{{ __('Next Follow-up Date') }}</span>
                        <span class="text-xs sm:text-sm font-bold text-amber-900 dark:text-amber-200">{{ $lead->next_follow_up_at->format('M j, Y') }}</span>
                    </div>
                </div>
                @if ($canUpdate)
                    <div class="flex items-center gap-1 ms-auto">
                        <flux:tooltip content="{{ __('Cancel follow-up') }}">
                            <flux:button size="xs" variant="ghost" icon="x-mark" aria-label="{{ __('Cancel follow-up') }}" wire:click="confirmCancelFollowUp" />
                        </flux:tooltip>
                        @if (! today()->lt($lead->next_follow_up_at))
                            <flux:tooltip content="{{ __('Mark follow-up as done') }}">
                                <flux:button size="xs" variant="primary" icon="check" aria-label="{{ __('Mark follow-up as done') }}" wire:click="markFollowUpDone" />
                            </flux:tooltip>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        @if ($lead->meeting_date)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-indigo-200/80 bg-indigo-50/30 p-3 dark:border-indigo-800/50 dark:bg-indigo-950/20">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-900/60 dark:text-indigo-300">
                        <flux:icon.calendar-days variant="micro" class="size-4" />
                    </div>
                    <div>
                        <span class="block text-[11px] font-medium text-indigo-700 dark:text-indigo-400">{{ __('Meeting Date') }}</span>
                        <span class="text-xs sm:text-sm font-bold text-indigo-900 dark:text-indigo-200">
                            {{ $lead->meeting_date->format('M j, Y') }}{{ $lead->meetingTimeFormatted() ? ' at '.$lead->meetingTimeFormatted() : '' }}
                        </span>
                    </div>
                </div>
                @if ($canUpdate)
                    <div class="flex items-center gap-1 ms-auto">
                        <flux:tooltip content="{{ __('Cancel meeting') }}">
                            <flux:button size="xs" variant="ghost" icon="x-mark" aria-label="{{ __('Cancel meeting') }}" wire:click="confirmCancelMeeting" />
                        </flux:tooltip>
                        @if (! today()->lt($lead->meeting_date))
                            <flux:tooltip content="{{ __('Mark meeting as done') }}">
                                <flux:button size="xs" variant="primary" icon="check" aria-label="{{ __('Mark meeting as done') }}" wire:click="markMeetingDone" />
                            </flux:tooltip>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#e4d6be] bg-[#f7f5f0] p-3 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center gap-2.5 min-w-0">
                <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <flux:icon.calendar variant="micro" class="size-4" />
                </div>
                <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Created Date') }}</span>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white">{{ $lead->created_at?->format('M j, Y') }}</span>
        </div>
    </div>
    </div>

    {{-- Skeleton: hidden by default, revealed only while
    initialLoadComplete's request is in flight — see leads.blade.php for
    why this has to be class-based, never a bare wire:loading. --}}
    <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} flex flex-col gap-3" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
        @for ($i = 0; $i < 5; $i++)
            <div wire:key="pipeline-attributes-skeleton-{{ $i }}" class="flex items-center justify-between gap-2">
                <div class="h-3 w-28 skeleton-shimmer rounded"></div>
                <div class="h-3 w-24 skeleton-shimmer rounded"></div>
            </div>
        @endfor
    </div>
</flux:card>

