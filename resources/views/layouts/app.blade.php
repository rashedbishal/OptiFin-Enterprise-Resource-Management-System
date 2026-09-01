<x-layouts::app.sidebar :title="$title ?? null">
    {{-- overflow-auto bounds this to its grid track (implicitly gets
    min-width/min-height: 0, the standard fix for a grid item otherwise
    refusing to shrink below its content size) and makes it the one
    scrolling region for page content — separate from the sidebar, which
    never scrolls or moves. --}}
    <flux:main class="overflow-auto">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
