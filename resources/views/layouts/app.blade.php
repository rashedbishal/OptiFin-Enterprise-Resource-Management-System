<x-layouts::app.sidebar :title="$title ?? null">
    {{-- overflow-auto bounds this to its grid track (implicitly gets
    min-width/min-height: 0, the standard fix for a grid item otherwise
    refusing to shrink below its content size) and makes it the one
    scrolling region for page content — separate from the sidebar, which
    never scrolls or moves. --}}
    <flux:main class="overflow-auto px-4 pt-0 pb-6 lg:px-8 lg:pt-0 lg:pb-8">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
