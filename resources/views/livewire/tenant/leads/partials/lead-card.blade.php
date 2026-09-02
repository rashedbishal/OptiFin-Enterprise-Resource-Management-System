@php $draggable ??= false; $canMove ??= false; $nextStatus = $lead->nextPipelineStatus(); @endphp

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
    class="flex cursor-pointer flex-col gap-2 rounded-lg border border-l-4 p-3 text-left shadow-sm transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-accent {{ $lead->status->cardBackgroundClasses() }} {{ $lead->status->cardAccentBorderClasses() }}"
>
    <div class="flex items-start justify-between gap-2">
        <p class="min-w-0 truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $lead->name }}</p>

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
