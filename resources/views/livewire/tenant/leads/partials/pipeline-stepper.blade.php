{{-- Read-only progress visualization — the actual status/priority change
control is the separate Status card elsewhere on the page (status-form.blade.php
if editable, otherwise nothing further since the badges already show it in
the hero above). Clicking a stage here does nothing on purpose. --}}
@php
    $pipeline = $lead->tenant->leadStatuses;

    // Full-page-only (like lead-hero.blade.php, never included by
    // show-panel.blade.php's flyout), so this is keyed off the page's own
    // one-time $initialLoadComplete flag — not $tabContentReady, since the
    // stepper sits above the tabs and isn't part of either tab's content,
    // it shouldn't re-shimmer on every switch. Purely a visual
    // reveal-together toggle, same reasoning as lead-hero.blade.php's
    // identical pair: $pipeline/$lead->status_id are already resolved by
    // the time this renders, nothing here is actually deferred.
    $listLoadingTargets = 'completeInitialLoad';
    $showSkeletonByDefault = ! $initialLoadComplete;
@endphp

<flux:card class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400">
                <flux:icon icon="adjustments-horizontal" variant="micro" class="size-4" />
            </div>
            <flux:heading size="sm" class="font-bold text-zinc-900 dark:text-white uppercase tracking-wider text-xs">
                {{ __('Status Pipeline Progression') }}
            </flux:heading>
        </div>
        @if ($lead->status)
            <flux:badge size="sm" :color="$lead->status->color()" class="font-semibold shadow-2xs">
                {{ __('Current: :status', ['status' => $lead->status->label()]) }}
            </flux:badge>
        @endif
    </div>

    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
        <div class="flex flex-wrap items-center gap-y-3 gap-2 sm:gap-3">
            @php $currentIndex = $pipeline->search(fn ($s) => $s->id === $lead->status_id); @endphp
            @foreach ($pipeline as $index => $statusCase)
                @php
                    $isCurrent = $statusCase->id === $lead->status_id;
                    $isPassed = $currentIndex !== false && $index < $currentIndex;
                @endphp
                <div class="flex items-center gap-1.5 sm:gap-2.5 w-[calc(50%-0.25rem)] sm:w-auto sm:flex-1 sm:min-w-[130px] sm:max-w-[220px]">
                    <div @class([
                        'flex w-full items-center justify-between gap-1.5 sm:gap-2 rounded-xl px-2.5 py-2 sm:py-2.5 border shadow-2xs transition-all duration-150',
                        'bg-zinc-100/90 border-zinc-200/90 dark:bg-zinc-900/80 dark:border-zinc-700/80' => ! $isCurrent && ! $isPassed,
                        'bg-indigo-50/90 border-indigo-300 dark:bg-indigo-950/60 dark:border-indigo-600 ring-2 ring-indigo-500/20 dark:ring-indigo-400/20 shadow-xs' => $isCurrent,
                        'bg-emerald-50/90 border-emerald-200/90 dark:bg-emerald-950/50 dark:border-emerald-800/60' => $isPassed,
                    ])>
                        <div class="flex items-center gap-2 min-w-0">
                            {{-- The current stage's circle gets an expanding, fading
                            ring in its own status color (LeadStatus::pulseRingClasses())
                            behind it — motion-safe: so prefers-reduced-motion drops the
                            animation entirely rather than just leaving a static ring,
                            same accessibility care as .lead-id-shimmer-text's own
                            reduced-motion override elsewhere in this module. --}}
                            <span class="relative flex size-6 shrink-0">
                                @if ($isCurrent)
                                    <span @class(['absolute inline-flex h-full w-full motion-safe:animate-ping rounded-full opacity-75', $statusCase->pulseRingClasses()])></span>
                                @endif

                                <span
                                    @class([
                                        'relative flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold shadow-2xs',
                                        $statusCase->cardBackgroundClasses().' '.$statusCase->companyNameTextClasses() => $isCurrent,
                                        'border-emerald-300 bg-emerald-100 text-emerald-800 dark:border-emerald-700 dark:bg-emerald-950/80 dark:text-emerald-300' => $isPassed,
                                        'border-zinc-300 bg-white text-zinc-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' => ! $isCurrent && ! $isPassed,
                                    ])
                                >
                                    @if ($isPassed)
                                        <flux:icon.check variant="micro" class="size-3.5 stroke-[2.5]" />
                                    @else
                                        {{ $index + 1 }}
                                    @endif
                                </span>
                            </span>
                            <span @class([
                                'text-xs sm:text-sm truncate',
                                'text-zinc-900 dark:text-white font-bold' => $isCurrent,
                                'text-emerald-950 dark:text-emerald-200 font-semibold' => $isPassed,
                                'text-zinc-700 dark:text-zinc-300 font-semibold' => ! $isCurrent && ! $isPassed,
                            ])>
                                {{ $statusCase->label() }}
                            </span>
                        </div>

                        @unless ($loop->last)
                            <flux:icon.chevron-right class="size-3.5 shrink-0 text-zinc-400 dark:text-zinc-500 sm:hidden" />
                        @endunless
                    </div>

                    @unless ($loop->last)
                        <flux:icon.chevron-right class="hidden sm:block mx-2 size-4 shrink-0 text-zinc-300 dark:text-zinc-600" />
                    @endunless
                </div>
            @endforeach
        </div>
    </div>

    {{-- Skeleton: hidden by default, revealed only while
    initialLoadComplete's request is in flight — see leads.blade.php for
    why this has to be class-based, never a bare wire:loading. --}}
    <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} contents" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
        <div class="flex flex-wrap items-center gap-2 gap-y-3 sm:gap-3">
            @for ($i = 0; $i < 5; $i++)
                <div wire:key="pipeline-stepper-skeleton-{{ $i }}" class="flex items-center gap-1.5 sm:gap-2.5 w-[calc(50%-0.25rem)] sm:w-auto sm:flex-1 sm:min-w-[130px]">
                    <div class="flex w-full items-center gap-2 rounded-xl bg-zinc-100/90 p-2.5 border border-zinc-200/80 dark:bg-zinc-900/80 dark:border-zinc-700/80">
                        <div class="size-6 shrink-0 skeleton-shimmer rounded-full"></div>
                        <div class="h-3.5 w-16 skeleton-shimmer rounded"></div>
                    </div>

                    @if ($i < 4)
                        <div class="hidden sm:block mx-2 h-3 w-3 shrink-0 skeleton-shimmer rounded-sm"></div>
                    @endif
                </div>
            @endfor
        </div>
    </div>
</flux:card>
