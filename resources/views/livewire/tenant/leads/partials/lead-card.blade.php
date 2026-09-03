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
        cardBackgroundClasses() fills the card's own bg + the 3 non-left
        borders; cardAccentBorderClasses() overrides just border-left-color
        with a brighter shade — a hover:border override on all sides would
        win the cascade for border-left-color too (shorthand beats a
        same-specificity longhand once :hover adds specificity) and wash the
        stripe out, so hover feedback here is shadow-only instead.
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
    class="flex w-full cursor-pointer flex-col gap-2 rounded-lg border border-l-4 p-3 text-left shadow-sm transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-accent {{ $lead->status->cardBackgroundClasses() }} {{ $lead->status->cardAccentBorderClasses() }}"
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="truncate font-mono text-[11px] text-zinc-400 dark:text-zinc-500">{{ $lead->identifier }}</p>
            <p class="min-w-0 truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $lead->name }}</p>
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

    <div class="flex flex-wrap items-center gap-1.5">
        <flux:badge size="sm" icon="{{ $lead->source->type->icon() }}">{{ $lead->source->type->label() }}</flux:badge>
        <flux:badge size="sm" :color="$lead->priority->color()">{{ $lead->priority->label() }}</flux:badge>
    </div>

    @if ($lead->service_interest)
        <flux:text size="sm" variant="subtle" class="truncate">{{ $lead->service_interest }}</flux:text>
    @endif

    @if ($lead->estimated_budget !== null || $lead->assignedUser)
        <div class="mt-1 flex items-center justify-between gap-2">
            <flux:text size="sm" variant="subtle">
                @if ($lead->estimated_budget !== null)
                    {{ $lead->estimatedBudgetCurrency?->code }} {{ number_format((float) $lead->estimated_budget, 0) }}
                @endif
            </flux:text>

            @if ($lead->assignedUser)
                <flux:avatar size="xs" :initials="$lead->assignedUser->initials()" />
            @endif
        </div>
    @endif

    @if ($canMove && $nextStatus)
        <div class="flex justify-end">
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
        </div>
    @endif
</div>

@if ($hasActiveAlert)
        </div>
        <flux:tooltip.content>
            <div class="flex flex-col gap-1">
                @if ($meetingAlertActive)
                    <div>{{ __('Meeting scheduled on :date', ['date' => $lead->meeting_date->format('l, M j, Y')]) }}</div>
                @endif
                @if ($followUpAlertActive)
                    <div>{{ __('Follow-up scheduled on :date', ['date' => $lead->next_follow_up_at->format('l, M j, Y')]) }}</div>
                @endif
            </div>
        </flux:tooltip.content>
    </flux:tooltip>
@endif
