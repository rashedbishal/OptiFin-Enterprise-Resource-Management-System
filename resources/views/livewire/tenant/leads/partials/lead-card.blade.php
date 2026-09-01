@php $draggable ??= false; @endphp

<div
    wire:key="lead-card-{{ $lead->id }}"
    role="button"
    tabindex="0"
    wire:click="viewLead('{{ $lead->id }}')"
    x-on:keydown.enter="$wire.viewLead('{{ $lead->id }}')"
    @if ($draggable)
        draggable="true"
        x-on:dragstart="$event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $lead->id }}'); $el.classList.add('opacity-50')"
        x-on:dragend="$el.classList.remove('opacity-50')"
    @endif
    class="flex cursor-pointer flex-col gap-2 rounded-lg border border-zinc-200 bg-white p-3 text-left shadow-sm transition hover:border-zinc-300 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-accent dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600"
>
    <div class="flex items-start justify-between gap-2">
        <p class="min-w-0 truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $lead->name }}</p>

        <div x-on:click.stop x-on:keydown.enter.stop>
            <flux:dropdown position="bottom" align="end">
                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="{{ __('Move to…') }}" />
                <flux:menu>
                    @foreach (\App\Enums\LeadStatus::cases() as $statusOption)
                        <flux:menu.item
                            wire:click="moveLead('{{ $lead->id }}', '{{ $statusOption->value }}')"
                            :disabled="$lead->status === $statusOption"
                        >
                            {{ $statusOption->label() }}
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
        </div>
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
                    {{ $tenantCurrency }} {{ number_format((float) $lead->estimated_budget, 0) }}
                @endif
            </flux:text>

            @if ($lead->assignedUser)
                <flux:avatar size="xs" :initials="$lead->assignedUser->initials()" />
            @endif
        </div>
    @endif
</div>
