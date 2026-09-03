{{-- Custom calendar dropdown, same shape as x-dropdown-select but for a
single date — native <input type="date"> can't show custom text as a
placeholder or inside its own popup (that popup is rendered entirely by the
browser/OS, with no hook for page content), so this builds our own popup
instead, with a visible heading ($label) and full month grid. --}}
@props([
    'label' => null,
    'placeholder' => '',
    'min' => null,
    'max' => null,
    'icon' => 'calendar',
])

@php
    $wireModelLive = $attributes->wire('model.live')->value();
    $wireModel = $attributes->wire('model')->value();
    $wireModelName = $wireModelLive ?: $wireModel;
@endphp

<div
    x-data="{
        open: false,
        selected: @if($wireModelLive) $wire.entangle('{{ $wireModelLive }}').live @elseif($wireModel) $wire.entangle('{{ $wireModel }}') @else null @endif,
        min: {{ json_encode($min ?: null) }},
        max: {{ json_encode($max ?: null) }},
        placeholder: {{ json_encode($placeholder) }},
        viewYear: null,
        viewMonth: null,
        init() { this.syncViewToSelected(); },
        syncViewToSelected() {
            const base = this.selected ? new Date(this.selected + 'T00:00:00') : new Date();
            this.viewYear = base.getFullYear();
            this.viewMonth = base.getMonth();
        },
        get displayText() {
            if (! this.selected) return this.placeholder;
            return new Date(this.selected + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        },
        get monthLabel() {
            return new Date(this.viewYear, this.viewMonth, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        },
        pad(n) { return String(n).padStart(2, '0'); },
        toIso(y, m, d) { return y + '-' + this.pad(m + 1) + '-' + this.pad(d); },
        daysInMonth(y, m) { return new Date(y, m + 1, 0).getDate(); },
        firstWeekday(y, m) { return new Date(y, m, 1).getDay(); },
        prevMonth() { this.viewMonth--; if (this.viewMonth < 0) { this.viewMonth = 11; this.viewYear--; } },
        nextMonth() { this.viewMonth++; if (this.viewMonth > 11) { this.viewMonth = 0; this.viewYear++; } },
        isDisabled(day) {
            const iso = this.toIso(this.viewYear, this.viewMonth, day);
            if (this.min && iso < this.min) return true;
            if (this.max && iso > this.max) return true;
            return false;
        },
        isSelectedDay(day) { return this.selected === this.toIso(this.viewYear, this.viewMonth, day); },
        isToday(day) {
            const t = new Date();
            return t.getFullYear() === this.viewYear && t.getMonth() === this.viewMonth && t.getDate() === day;
        },
        select(day) {
            if (this.isDisabled(day)) return;
            this.selected = this.toIso(this.viewYear, this.viewMonth, day);
            this.open = false;
        },
        clear() { this.selected = ''; },
    }"
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
    class="relative inline-block w-full text-left"
>
    <button
        type="button"
        x-on:click="open = !open; if (open) syncViewToSelected();"
        :aria-expanded="open"
        {{ $attributes->except(['wire:model', 'wire:model.live'])->merge([
            'class' => 'flex w-full items-center gap-2 rounded-lg border border-zinc-300/80 bg-zinc-100/90 px-3 py-1.5 text-xs sm:text-sm font-medium text-zinc-800 shadow-xs transition hover:border-zinc-400 hover:bg-zinc-200/80 focus:outline-none focus:ring-2 focus:ring-accent/40 dark:border-zinc-700/80 dark:bg-zinc-900/90 dark:text-zinc-200 dark:hover:border-zinc-600 dark:hover:bg-zinc-800 cursor-pointer'
        ]) }}
    >
        <flux:icon :icon="$icon" variant="micro" class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400" />
        <span x-text="displayText" class="min-w-0 flex-1 truncate text-start" :class="selected ? '' : 'text-zinc-400 dark:text-zinc-500'"></span>
        <span
            x-show="selected"
            x-on:click.stop="clear()"
            class="shrink-0 rounded-full p-0.5 text-zinc-400 hover:bg-zinc-300/60 hover:text-zinc-600 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
            role="button"
            aria-label="{{ __('Clear date') }}"
        >
            <flux:icon.x-mark variant="micro" class="size-3.5" />
        </span>
    </button>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
        x-cloak
        class="absolute left-0 z-50 mt-1 w-64 rounded-xl border border-zinc-300/90 bg-white/98 p-3 shadow-xl backdrop-blur-md focus:outline-none dark:border-zinc-700/90 dark:bg-zinc-900/95"
    >
        @if ($label)
            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
        @endif

        <div class="mb-2 flex items-center justify-between">
            <button type="button" x-on:click="prevMonth()" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                <flux:icon.chevron-left variant="micro" class="size-4" />
            </button>
            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200" x-text="monthLabel"></span>
            <button type="button" x-on:click="nextMonth()" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                <flux:icon.chevron-right variant="micro" class="size-4" />
            </button>
        </div>

        <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-zinc-400 dark:text-zinc-500">
            <span>{{ __('Su') }}</span><span>{{ __('Mo') }}</span><span>{{ __('Tu') }}</span><span>{{ __('We') }}</span><span>{{ __('Th') }}</span><span>{{ __('Fr') }}</span><span>{{ __('Sa') }}</span>
        </div>

        <div class="mt-1 grid grid-cols-7 gap-1">
            <template x-for="n in firstWeekday(viewYear, viewMonth)" :key="'pad-' + n">
                <span></span>
            </template>
            <template x-for="day in daysInMonth(viewYear, viewMonth)" :key="day">
                <button
                    type="button"
                    x-on:click="select(day)"
                    :disabled="isDisabled(day)"
                    x-text="day"
                    class="flex aspect-square items-center justify-center rounded-md text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-30 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    ::class="{
                        'bg-accent text-accent-foreground hover:bg-accent': isSelectedDay(day),
                        'ring-1 ring-inset ring-accent/60': isToday(day) && !isSelectedDay(day),
                        'text-zinc-700 dark:text-zinc-200': !isSelectedDay(day),
                    }"
                ></button>
            </template>
        </div>
    </div>

    @if ($wireModelName && $errors->has($wireModelName))
        <flux:error class="mt-1 text-xs">{{ $errors->first($wireModelName) }}</flux:error>
    @endif
</div>
