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

<div class="flex flex-col gap-2">
    <flux:text size="sm" variant="strong" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
        {{ __('Status Pipeline Progression') }}
    </flux:text>

    {{-- Real content: hidden by default before initialLoadComplete, toggled
    by wire:loading otherwise — never a bare wire:loading/.remove, see
    leads.blade.php's long comment for why. --}}
    <div wire:loading.class="!hidden" wire:target="{{ $listLoadingTargets }}" class="{{ $showSkeletonByDefault ? '!hidden' : '' }} contents">
    {{-- A numbered step + chevron-separator pipeline (same connector visual
    language as <x-breadcrumbs>), in a flex-wrap row rather than a fixed
    grid of boxes or a horizontal-scroll row — each [circle+label] unit
    wraps onto the next line as a whole once it doesn't fit, so there's
    never a scrollbar at any viewport no matter how many stages the
    tenant's pipeline has. max-w-40 on each unit means even an unusually
    long custom status name wraps inside its own step instead of pushing
    the row wider. The circle itself carries the stage number, so there's
    no separate "Stage N" caption any more — it'd just repeat what the
    circle already shows. --}}
    <div class="flex flex-wrap items-center gap-y-3">
        @foreach ($pipeline as $index => $statusCase)
            @php $isCurrent = $statusCase->id === $lead->status_id; @endphp
            <div class="flex max-w-40 items-center gap-2">
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
                            'relative flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold',
                            $statusCase->cardBackgroundClasses().' '.$statusCase->companyNameTextClasses() => $isCurrent,
                            'border-zinc-300 bg-white text-zinc-400 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-500' => ! $isCurrent,
                        ])
                    >
                        {{ $index + 1 }}
                    </span>
                </span>
                <flux:text size="sm" class="{{ $isCurrent ? 'font-semibold' : '' }}">{{ $statusCase->label() }}</flux:text>
            </div>

            @unless ($loop->last)
                <flux:icon.chevron-right class="mx-2 size-4 shrink-0 text-zinc-300 dark:text-zinc-600" />
            @endunless
        @endforeach
    </div>
    </div>

    {{-- Skeleton: hidden by default, revealed only while
    initialLoadComplete's request is in flight — see leads.blade.php for
    why this has to be class-based, never a bare wire:loading. Item count
    (5) and widths are arbitrary, just enough steps to fill a typical row
    at desktop width without implying a specific pipeline length. The
    connector placeholder is a plain shimmer block, not the real
    flux:icon.chevron-right — reusing its exact classes would inflate
    PipelineStepperTest's chevron-count assertion, since both this and the
    real content render into the same html() output (only CSS-hidden, not
    removed from the DOM). --}}
    <div class="{{ $showSkeletonByDefault ? '' : '!hidden' }} flex flex-wrap items-center gap-y-3" wire:loading.remove.class="!hidden" wire:target="{{ $listLoadingTargets }}">
        @for ($i = 0; $i < 5; $i++)
            <div wire:key="pipeline-stepper-skeleton-{{ $i }}" class="flex max-w-40 items-center gap-2">
                <div class="size-6 shrink-0 skeleton-shimmer rounded-full"></div>
                <div class="h-3.5 w-16 skeleton-shimmer rounded"></div>
            </div>

            @if ($i < 4)
                <div class="mx-2 h-3 w-3 shrink-0 skeleton-shimmer rounded-sm"></div>
            @endif
        @endfor
    </div>
</div>
