<x-layouts::app.sidebar :title="$title ?? null">
    {{-- overflow-auto bounds this to its grid track (implicitly gets
    min-width/min-height: 0, the standard fix for a grid item otherwise
    refusing to shrink below its content size) and makes it the one
    scrolling region for page content — separate from the sidebar, which
    never scrolls or moves. --}}
    <flux:main class="flex flex-col h-full min-h-0 overflow-hidden !p-0">
        {{-- Shown at every viewport width — the single, only mount of the
        bell (the mobile header in layouts/app/sidebar.blade.php doesn't
        carry its own copy; two live Livewire instances would each run
        their own unread-count query and hold their own Echo subscription
        for a bell only one of which is ever visible at a time). Each
        tenant page pushes its own breadcrumb into this same bar via
        @push('breadcrumbs') instead of rendering it inline in its own
        content — see any of resources/views/livewire/tenant/*.blade.php
        for the pattern.

        The breadcrumb wrapper is flex-1 rather than the bar itself using
        justify-between: with justify-between, a breadcrumb hidden or empty
        on some viewport leaves the bell as the *only* visible flex child,
        and justify-content: space-between collapses to flex-start (left)
        for a single item — not the right, which is the bug this fixes.
        flex-1 always reserves the leading space for the breadcrumb (empty
        or not), so the bell is pushed to the end unconditionally. --}}
        {{-- relative z-[15]: backdrop-blur below creates a new stacking
        context, which would otherwise trap the notification panel's
        z-50 inside it — a later sibling with a lower z-index (e.g. the
        Kanban filter bar's z-10) could then paint above this whole bar,
        panel included, since the two z-indexes are no longer compared
        in the same context. Needs to clear that z-10 but must stay
        BELOW z-20 — Flux's mobile sidebar drawer opens at z-20! (see
        vendor/livewire/flux/stubs/resources/views/flux/sidebar/index.blade.php),
        and this bar previously used z-30, which painted over the open
        drawer on mobile. z-[15] sits in the gap between the two. --}}
        <div class="app-topbar relative z-[15] flex shrink-0 items-center gap-2 border-b border-zinc-200/70 bg-[#FDFCF7]/90 px-4 py-2 backdrop-blur-sm dark:border-zinc-700/70 dark:bg-[#0e1826]/90">
            <div class="min-w-0 flex-1">
                @stack('breadcrumbs')
            </div>

            <livewire:tenant.notification-bell />
        </div>

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
