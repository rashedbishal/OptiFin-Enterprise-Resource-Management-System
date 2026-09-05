{{-- Read-only progress visualization — the actual status/priority change
control is the separate Status card elsewhere on the page (status-form.blade.php
if editable, otherwise nothing further since the badges already show it in
the hero above). Clicking a stage here does nothing on purpose. --}}
@php $pipeline = $lead->tenant->leadStatuses; @endphp

<div class="flex flex-col gap-2">
    <flux:text size="sm" variant="strong" class="uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
        {{ __('Status Pipeline Progression') }}
    </flux:text>

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
