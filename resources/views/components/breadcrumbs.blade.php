@props(['items' => []])

@php
    $items = collect($items)->values();
    $lastIndex = $items->count() - 1;
@endphp

<nav aria-label="{{ __('Breadcrumb') }}" class="mb-4 flex min-w-0 items-center gap-1 overflow-x-auto text-sm leading-none">
    @foreach ($items as $index => $item)
        @php
            $isFirst = $index === 0;
            $isLast = $index === $lastIndex;
            $href = $isLast ? null : ($item['href'] ?? null);
        @endphp

        @if (! $isFirst)
            <flux:icon.chevron-right class="size-3.5 shrink-0 text-zinc-300 dark:text-zinc-600" />
        @endif

        @if ($href)
            <flux:link
                :href="$href"
                wire:navigate
                class="flex shrink-0 items-center gap-1.5 !text-zinc-500 transition-colors hover:!text-zinc-700 dark:!text-zinc-400 dark:hover:!text-zinc-200"
            >
                @if ($isFirst)
                    <flux:icon.home variant="micro" class="size-3.5" />
                @endif
                <span>{{ $item['label'] }}</span>
            </flux:link>
        @else
            <span
                class="flex min-w-0 items-center gap-1.5 font-medium text-zinc-700 dark:text-zinc-200"
                @if ($isLast) aria-current="page" @endif
            >
                @if ($isFirst)
                    <flux:icon.home variant="micro" class="size-3.5 shrink-0" />
                @endif
                <span class="truncate">{{ $item['label'] }}</span>
            </span>
        @endif
    @endforeach
</nav>
