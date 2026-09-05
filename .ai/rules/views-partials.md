---
paths:
  - 'resources/views/layouts/app.blade.php,resources/views/livewire/tenant/**,resources/views/partials/settings-heading.blade.php'
---

# Views Partials

## Breadcrumbs live in the shared top bar via @push/@stack, not inline per-page
layouts/app.blade.php's top bar (the one with the notification bell) now has `@stack('breadcrumbs')` on the left, wrapped in a `hidden sm:flex` container — that's the ONE place breadcrumb visibility is controlled now; don't put `hidden sm:flex` back on an individual page's `<x-breadcrumbs>` call. Every tenant page (Dashboard, Users, Roles, Lead Sources, Leads index/kanban/show/source-leads, Settings via partials/settings-heading.blade.php) pushes its own breadcrumb with `@push('breadcrumbs') <x-breadcrumbs :items="[...]" /> @endpush` placed before its own root element, instead of rendering `<x-breadcrumbs>` inline as the first child of that root div like it used to. This does NOT violate the "full-page Livewire view must have a single root element" rule — `@push` produces no output at its own call site (the pushed content is drained later by `@stack` in the layout, a separate render pass), so Livewire's root-element detection never sees it. Confirmed working end-to-end (not just "doesn't error") via a test asserting `aria-label="Breadcrumb"` appears exactly once in the real rendered HTTP response for every one of these pages.

Any NEW tenant page must follow the same pattern — push its breadcrumb, don't render it inline — or it simply won't appear (there's no fallback rendering path left in the individual page views). Note this only works when the page is reached through a real HTTP request; `Livewire::test(SomeComponent::class)`-style component tests render only the component's own view, never the surrounding layout, so `@stack` never drains in that context — asserting on the breadcrumb's presence needs an actual `$this->get(route(...))` call instead (see the dedicated test added for SourceLeads, which is normally only tested via Livewire::test()).
