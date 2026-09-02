@props([
    'placeholder' => '',
    'options' => [],
    'icon' => null,
    'label' => null,
])

@php
    $formattedOptions = [];
    foreach ($options as $key => $val) {
        if (is_array($val) && isset($val['value'])) {
            $formattedOptions[] = [
                'value' => (string) $val['value'],
                'label' => (string) ($val['label'] ?? $val['value']),
            ];
        } elseif (is_numeric($key) && ! is_array($val)) {
            $formattedOptions[] = [
                'value' => (string) $val,
                'label' => (string) $val,
            ];
        } else {
            $formattedOptions[] = [
                'value' => (string) $key,
                'label' => (string) $val,
            ];
        }
    }

    $wireModelLive = $attributes->wire('model.live')->value();
    $wireModel = $attributes->wire('model')->value();
    $wireModelName = $wireModelLive ?: $wireModel;
@endphp

<div
    x-data="{
        open: false,
        selected: @if($wireModelLive) $wire.entangle('{{ $wireModelLive }}').live @elseif($wireModel) $wire.entangle('{{ $wireModel }}') @else null @endif,
        options: {{ json_encode($formattedOptions) }},
        placeholder: {{ json_encode($placeholder) }},
        get label() {
            if (this.selected === '' || this.selected === null || this.selected === undefined) {
                return this.placeholder;
            }
            const match = this.options.find(o => String(o.value) === String(this.selected));
            return match ? match.label : this.placeholder;
        }
    }"
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
    class="relative inline-block w-full text-left"
>
    @if ($label)
        <flux:label class="mb-1 text-xs font-medium sm:text-sm text-zinc-700 dark:text-zinc-300">{{ $label }}</flux:label>
    @endif

    <button
        type="button"
        x-on:click="open = !open"
        :aria-expanded="open"
        {{ $attributes->except(['wire:model', 'wire:model.live', 'label'])->merge([
            'class' => 'flex w-full items-center justify-between gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs sm:text-sm font-medium text-zinc-700 shadow-xs transition hover:border-zinc-300 hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-accent/40 dark:border-zinc-700 dark:bg-zinc-800/90 dark:text-zinc-200 dark:hover:border-zinc-600 dark:hover:bg-zinc-700/50 cursor-pointer'
        ]) }}
    >
        <span class="flex items-center gap-2 truncate">
            @if ($icon)
                <flux:icon :name="$icon" variant="micro" class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
            @endif
            <span x-text="label" class="truncate"></span>
        </span>

        <flux:icon.chevron-down
            variant="micro"
            class="size-4 shrink-0 text-zinc-400 transition-transform duration-200 dark:text-zinc-500"
            ::class="{ 'rotate-180': open }"
        />
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
        class="absolute left-0 z-50 mt-1 max-h-60 w-full min-w-44 overflow-auto rounded-xl border border-zinc-200/80 bg-white/95 p-1 shadow-xl backdrop-blur-md focus:outline-none dark:border-zinc-700/80 dark:bg-zinc-800/95"
    >
        <template x-for="option in options" :key="option.value">
            <button
                type="button"
                x-on:click="selected = option.value; open = false;"
                class="flex w-full items-center justify-between rounded-lg px-2.5 py-1.5 text-xs sm:text-sm font-medium transition cursor-pointer text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-700/60"
                ::class="{ 'bg-zinc-100/80 dark:bg-zinc-700/70 font-semibold text-zinc-900 dark:text-white': String(selected) === String(option.value) }"
            >
                <span x-text="option.label" class="truncate"></span>
                <flux:icon.check
                    variant="micro"
                    x-show="String(selected) === String(option.value)"
                    class="size-4 shrink-0 text-accent"
                />
            </button>
        </template>
    </div>

    @if ($wireModelName && $errors->has($wireModelName))
        <flux:error class="mt-1 text-xs">{{ $errors->first($wireModelName) }}</flux:error>
    @endif
</div>
