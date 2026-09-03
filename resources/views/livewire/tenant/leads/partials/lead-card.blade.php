@php
    $draggable ??= false;
    $canMove ??= false;
    $nextStatus = $lead->nextPipelineStatus();

    $meetingAlertActive = $lead->meetingAlertActive($tenant->meeting_alert_enabled, $tenant->meeting_alert_days_before);
    $followUpAlertActive = $lead->followUpAlertActive($tenant->followup_alert_enabled, $tenant->followup_alert_days_before);
    $hasActiveAlert = $meetingAlertActive || $followUpAlertActive;
    $shimmerRgb = $hasActiveAlert ? $lead->status->alertShimmerRgb() : null;
@endphp

{{-- ui-tooltip defaults to display:inline-flex (see flux.css), which
    shrinks to content width instead of filling the column like the
    card normally does — class="w-full" here restores that stretch. --}}
@if ($hasActiveAlert)
    <flux:tooltip position="bottom" class="w-full">
        {{--
            The ring is a masked sibling overlay (position:absolute,
            inset-0), not a wrapper the card sits inside of — masking an
            element clips its ENTIRE rendered subtree, backgrounds AND
            children alike, so wrapping the card in a masked frame hid the
            card's own content, not just confined the gradient to the edge.
            As a childless sibling instead, masking it only ever affects its
            own empty box.
        --}}
        <div class="relative w-full">
            <div
                class="lead-alert-shimmer pointer-events-none absolute inset-0 rounded-lg"
                style="--shimmer-light: {{ $shimmerRgb['light'] }}; --shimmer-dark: {{ $shimmerRgb['dark'] }};"
                aria-hidden="true"
            ></div>
@endif

<div
    wire:key="lead-card-{{ $lead->id }}"
    role="button"
    tabindex="0"
    wire:click="viewLead('{{ $lead->id }}')"
    x-on:keydown.enter="$wire.viewLead('{{ $lead->id }}')"
    @if ($draggable && $canMove)
        draggable="true"
        x-on:dragstart="$event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $lead->id }}'); $el.classList.add('opacity-50')"
        x-on:dragend="$el.classList.remove('opacity-50')"
    @endif
    {{--
        The inset-ring is a thin light bevel on top of cardBackgroundClasses()'s
        own uniform border — inset-ring uses its own --tw-inset-ring-shadow
        layer (Tailwind v4), composing with shadow-sm/hover:shadow-md's
        --tw-shadow rather than overwriting it, which is what makes the
        "raised" look additive instead of a flat replacement.

        This lives entirely on the card's own box — the shimmer alert overlay
        is a separate absolutely-positioned sibling (see below), so changing
        the card's border/ring/shadow here has no effect on it either way.
    --}}
    {{--
        w-full matters only when $hasActiveAlert wraps this card in
        <flux:tooltip> below: ui-tooltip is display:inline-flex with the
        default row direction, and a flex item's main-axis (width, in a row
        container) is never auto-stretched by align-items — only cross-axis
        (height) is — so without an explicit width this card would shrink
        to its content's width instead of filling the column like it does
        unwrapped. Harmless no-op when not wrapped.
    --}}
    class="flex w-full cursor-pointer flex-col gap-2 rounded-lg border p-3 text-left shadow-sm inset-ring-1 inset-ring-white/60 transition hover:shadow-md dark:inset-ring-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent {{ $lead->status->cardBackgroundClasses() }}"
>
    <div class="flex items-start justify-between gap-2">
        <div class="flex min-w-0 flex-nowrap items-center gap-1.5">
            <flux:badge size="sm" variant="pill" class="shrink-0 px-1.5 py-0.5 font-mono text-[8px] leading-none">{{ $lead->identifier }}</flux:badge>
            <flux:badge size="sm" variant="pill" :color="$lead->priority->color()" class="shrink-0 px-1.5 py-0.5 text-[8px] leading-none">{{ $lead->priority->label() }}</flux:badge>
        </div>

        @if ($canMove)
            <div class="flex shrink-0 items-center" x-on:click.stop x-on:keydown.enter.stop>
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="{{ __('Move to…') }}" />
                    <flux:menu>
                        @foreach ($tenantStatuses as $statusOption)
                            <flux:menu.item
                                wire:click="moveLead('{{ $lead->id }}', '{{ $statusOption->id }}')"
                                :disabled="$lead->status_id === $statusOption->id"
                            >
                                {{ $statusOption->label() }}
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            </div>
        @endif
    </div>

    <div class="min-w-0">
        @if ($lead->company)
            <div class="mb-0.5 flex items-center gap-1 text-sm font-semibold {{ $lead->status->companyNameTextClasses() }}">
                <flux:icon icon="building-office-2" variant="micro" class="shrink-0" />
                <span class="truncate">{{ $lead->company }}</span>
            </div>
        @endif

        <p class="min-w-0 truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $lead->name }}</p>

        @if ($lead->service_interest)
            <flux:badge variant="pill" color="zinc" class="mt-2 max-w-full truncate">
                {{ $lead->service_interest }}
            </flux:badge>
        @endif
    </div>

    @if ($lead->deal_value !== null || $lead->win_probability !== null)
        <div class="mt-1 grid grid-cols-2 gap-2 rounded-md border border-zinc-950/10 bg-white/60 p-2 dark:border-white/10 dark:bg-white/5">
            <div class="min-w-0">
                <p class="text-[7px] leading-none font-medium tracking-wide text-zinc-400 uppercase dark:text-zinc-500">{{ __('Deal Value') }}</p>
                <p class="truncate text-sm font-medium text-zinc-700 dark:text-zinc-200">
                    @if ($lead->deal_value !== null)
                        {{ $lead->dealValueCurrency?->code }} {{ number_format((float) $lead->deal_value, 0) }}
                    @else
                        &mdash;
                    @endif
                </p>
            </div>

            <div class="min-w-0 text-right">
                <p class="text-[7px] leading-none font-medium tracking-wide text-zinc-400 uppercase dark:text-zinc-500">{{ __('Win Probability') }}</p>
                <p class="truncate text-sm font-medium text-zinc-700 dark:text-zinc-200">
                    @if ($lead->win_probability !== null)
                        {{ $lead->win_probability }}%
                    @else
                        &mdash;
                    @endif
                </p>
            </div>
        </div>
    @endif

    <div class="mt-1 flex items-center justify-between gap-2">
        <flux:badge size="sm" icon="{{ $lead->source->type->icon() }}" class="px-1.5 py-0.5 text-[10px] **:data-flux-badge-icon:size-3">
            {{ $lead->source->type->label() }}
        </flux:badge>

        @if ($canMove && $nextStatus)
            <flux:tooltip content="{{ __('Advance to :status', ['status' => $nextStatus->label()]) }}">
                <flux:button
                    size="xs"
                    variant="primary"
                    :color="$nextStatus->color()"
                    icon:trailing="arrow-right"
                    wire:click.stop="moveLead('{{ $lead->id }}', '{{ $nextStatus->id }}')"
                >
                    {{ __('Advance') }}
                </flux:button>
            </flux:tooltip>
        @endif
    </div>
</div>

@if ($hasActiveAlert)
        </div>
        <flux:tooltip.content>
            <div class="flex flex-col gap-1">
                @if ($meetingAlertActive)
                    <div>
                        {{ __('Meeting scheduled on :date', ['date' => $lead->meeting_date->format('l, M j, Y')]) }}
                        {{ $lead->meetingTimeFormatted() ? __('at :time', ['time' => $lead->meetingTimeFormatted()]) : '' }}
                    </div>
                @endif
                @if ($followUpAlertActive)
                    <div>{{ __('Follow-up scheduled on :date', ['date' => $lead->next_follow_up_at->format('l, M j, Y')]) }}</div>
                @endif
            </div>
        </flux:tooltip.content>
    </flux:tooltip>
@endif
