@props(['items' => []])

@php
    $items = collect($items)->values();
    $lastIndex = $items->count() - 1;
@endphp

<nav aria-label="{{ __('Breadcrumb') }}" class="mt-1 flex min-w-0 shrink-0 items-center overflow-x-auto text-sm">
    @foreach ($items as $index => $item)
        @php
            $isFirst = $index === 0;
            $isLast = $index === $lastIndex;
            $href = $isLast ? null : ($item['href'] ?? null);
        @endphp

        @if (! $isFirst)
            <flux:icon.chevron-right class="mx-1.5 size-3.5 shrink-0 text-zinc-300 dark:text-zinc-600" />
        @endif

        {{-- Icon and label sit in a flex container we own, never inside <flux:link> directly:
             Tailwind's preflight makes <svg> display:block, and a block child inside the
             link's own `inline` box forces it onto its own line. --}}
        <span class="flex min-w-0 items-center gap-1.5 {{ $isLast ? '' : 'shrink-0' }}">
            @if ($isFirst)
                <flux:icon.home variant="micro" class="size-3.5 shrink-0 text-zinc-400 dark:text-zinc-500" />
            @endif

            @if ($href)
                <flux:link :href="$href" wire:navigate variant="subtle" class="!font-medium">
                    {{ $item['label'] }}
                </flux:link>
            @else
                <span
                    class="max-w-[16rem] truncate font-semibold text-zinc-700 dark:text-zinc-200 sm:max-w-xs"
                    @if ($isLast) aria-current="page" @endif
                >
                    {{ $item['label'] }}
                </span>
            @endif
        </span>
    @endforeach
</nav>
