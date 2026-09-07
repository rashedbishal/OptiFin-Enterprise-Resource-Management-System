---
paths:
  - 'resources/views/livewire/**,app/Livewire/**'
---

# Livewire Livewire

## Recipe: adding skeleton-loading shimmer to a new Livewire table/list/board
When asked to add skeleton-loading shimmer to a new list/table/board view (say "add skeleton loading like the Leads table/Kanban board" and point at the target), follow this exact recipe — it's the accumulated result of several rounds of real bugs on the Leads module (table: `leads.blade.php`; Kanban: `leads-kanban.blade.php`; Facebook Lead Ads spreadsheet: `leads/source-leads.blade.php` — read those three plus their `Index.php`/`SourceLeads.php` for working reference implementations before writing new code).

**1. First-load deferral** (shows the skeleton on the very first page visit, not just later interactions — a real perf win too, not just UX): add `public bool $initialLoadComplete = false` + a trivial `completeInitialLoad(): void { $this->initialLoadComplete = true; }` method to the Livewire component. Gate the actual data query (the shared query-building method, if one exists) behind it: `if (! $this->initialLoadComplete) { return $query->whereRaw('1 = 0'); }` — cheap, keeps the correct return type, no special-casing downstream. Add `wire:init="completeInitialLoad"` to the view's root element.

**2. `$listLoadingTargets`**: one string listing every property/method that legitimately re-fetches this list — every `wire:model.live` filter property, every pagination-trait method the page can call (`gotoPage,previousPage,nextPage,setPage`), any per-viewport page-size setter, and `completeInitialLoad`. Every real/skeleton toggle below uses `wire:target="{{ $listLoadingTargets }}"` — this is what keeps the skeleton from firing on unrelated actions (opening a modal, exporting, deleting a row).

**3. Never a bare `wire:loading`/`wire:loading.remove`** on a `<tbody>`, `<tr>`, or a `display:contents` div. Livewire's non-`.class` display toggling only supports `inline, list-item, block, table, flex, grid, inline-flex` — anything else (including `table-row-group`/`table-row`/`contents`) falls back to forcing `display: inline-block`, breaking table layout and, worse, permanently corrupting a `.remove` element to `inline-block` after its first completed loading cycle. Always use `wire:loading.class="!hidden"` (hide during loading) or `wire:loading.remove.class="!hidden"` (hidden by default via a baked-in `!hidden` static class, removed while loading) instead — `.class` only ever touches `classList`, never `el.style.display`.

**4. The pre-hydration flash**: `wire:loading` toggles only take effect once Livewire's JS actually dispatches a request — there's a real gap between raw HTML paint and that first request firing, during which NEITHER toggle has run and both sides show their untoggled default (real content visible-but-genuinely-empty = old empty-state flashes; skeleton hidden). Fix: compute `$showSkeletonByDefault = ! $initialLoadComplete` once, and make the STATIC baked-in class on both the real and skeleton elements conditional on it (`{{ $showSkeletonByDefault ? '!hidden' : '' }}`, or `@class(['!hidden' => $showSkeletonByDefault])` on Flux components) — resolved server-side at render time, so there's no JS-timing window where it can be wrong.

**5. Skeleton sizing**: match real row/card count to the actual per-page count (a fixed arbitrary count makes the container visibly shrink then snap back once real data loads). For a table with a dynamic column count (e.g. per-tenant custom fields), loop the skeleton's cell count to match. For a Kanban-style board, columns/groups that already render unconditionally (real, cheap metadata) just need their inner content toggled; a real list that hides on empty (mobile status groups) needs a separate always-rendered skeleton block since which groups will end up empty isn't knowable before the fetch completes.

**6. Shimmer CSS**: use the existing `.skeleton-shimmer` class (`resources/css/app.css`) on placeholder bars — don't reinvent it, don't use Tailwind's `animate-pulse` (rejected earlier in favor of a proper sweeping-gradient shimmer).

**7. Test fallout**: any `Livewire::test(Component::class)` touching the gated query needs `->call('completeInitialLoad')` chained on, or it asserts against the guaranteed-empty pre-load state. Exception: a test asserting an immediate `abort()`/404 from `mount()` must NOT chain it — the component never reaches a valid post-mount state to call it on.

**8. Comment-writing trap**: never write the literal token `@php` inside a `{{-- Blade comment --}}` — Blade's directive parser matches it even inside comments and breaks compilation with a cryptic `Unmatched ')'`. Say "PHP block" instead.
