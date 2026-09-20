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
    <flux:heading size="lg">{{ __('Pipeline Attributes') }}</flux:heading>

    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
    <div class="flex flex-col gap-3">
        {{-- Labeled "Source Channel" rather than "Lead Source" to avoid
        colliding with $lead->lead_source below, a different, manual-entry-
        only free-text field (see .ai/rules/leads.md). --}}
        <div class="flex items-center justify-between gap-2">
            <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Source Channel:') }}</span>
            <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white">{{ $lead->source->type->label() }}</span>
        </div>

        @if ($lead->industry)
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Industry:') }}</span>
                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white text-end break-words">{{ $lead->industry }}</span>
            </div>
        @endif

        {{-- lead_source here is the manual-entry-only free-text field
        (e.g. "Referral", "Trade Show") — distinct from the source() channel
        above. Only ever set on manual leads, see .ai/rules/leads.md. --}}
        @if ($isManual && $lead->lead_source)
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Referred Via:') }}</span>
                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white text-end break-words">{{ $lead->lead_source }}</span>
            </div>
        @endif

        <div class="flex items-center justify-between gap-2">
            <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Assigned Sales Executive:') }}</span>
            <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white text-end">{{ $lead->assignedUser?->name ?? __('Unassigned') }}</span>
        </div>

        @if ($lead->estimated_budget !== null)
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Est. Budget:') }}</span>
                <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white">
                    {{ $lead->estimatedBudgetCurrency?->code }} {{ number_format((float) $lead->estimated_budget, 2) }}
                </span>
            </div>
        @endif

        @if ($lead->next_follow_up_at)
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Next Follow-up Date:') }}</span>
                <div class="flex items-center gap-1">
                    <span class="text-xs sm:text-sm font-bold text-amber-600 dark:text-amber-400">{{ $lead->next_follow_up_at->format('M j, Y') }}</span>
                    @if ($canUpdate)
                        <flux:tooltip content="{{ __('Cancel follow-up') }}">
                            <flux:button size="sm" variant="ghost" icon="x-mark" aria-label="{{ __('Cancel follow-up') }}" wire:click="confirmCancelFollowUp" />
                        </flux:tooltip>
                        @if (! today()->lt($lead->next_follow_up_at))
                            <flux:tooltip content="{{ __('Mark follow-up as done') }}">
                                <flux:button size="sm" variant="primary" icon="check" aria-label="{{ __('Mark follow-up as done') }}" wire:click="markFollowUpDone" />
                            </flux:tooltip>
                        @endif
                    @endif
                </div>
            </div>
        @endif

        @if ($lead->meeting_date)
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Meeting Date:') }}</span>
                <div class="flex items-center gap-1">
                    <span class="text-xs sm:text-sm font-semibold text-zinc-900 dark:text-white text-end">
                        {{ $lead->meeting_date->format('M j, Y') }}{{ $lead->meetingTimeFormatted() ? ' at '.$lead->meetingTimeFormatted() : '' }}
                    </span>
                    @if ($canUpdate)
                        <flux:tooltip content="{{ __('Cancel meeting') }}">
                            <flux:button size="sm" variant="ghost" icon="x-mark" aria-label="{{ __('Cancel meeting') }}" wire:click="confirmCancelMeeting" />
                        </flux:tooltip>
                        @if (! today()->lt($lead->meeting_date))
                            <flux:tooltip content="{{ __('Mark meeting as done') }}">
                                <flux:button size="sm" variant="primary" icon="check" aria-label="{{ __('Mark meeting as done') }}" wire:click="markMeetingDone" />
                            </flux:tooltip>
                        @endif
                    @endif
                </div>
            </div>
        @endif

        <div class="flex items-center justify-between gap-2">
            <span class="text-xs sm:text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Created Date:') }}</span>
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
