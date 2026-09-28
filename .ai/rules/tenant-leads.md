---
paths:
  - 'resources/views/livewire/tenant/leads/**'
  - resources/views/livewire/tenant/leads/source-leads.blade.php
  - 'resources/views/livewire/tenant/leads/{show,show-panel}.blade.php'
---

# Tenant Leads

## Don't combine flex-1 + h-full inside a CSS Grid row with align-items:stretch
Tried twice in the lead panel's Contact/Company/Status/Note cards: giving a card `h-full` + its form `flex h-full flex-col`, with a `flex-1` on an inner field (e.g. a textarea) so a trailing button could sit at `mt-auto`, to make row-mates in a CSS Grid match height.

Both times it backfired — the `flex-1` child's intrinsic-size contribution to the grid's auto row-height calculation gets wildly inflated (a circular sizing dependency between the flex-grow item and the indefinite/percentage-height ancestor), so the whole row's height balloons and every card in it gets stretched to match, leaving 300+px of dead space between the fields and the button ("button looks like it's outside the card").

Current fix: these 4 cards are plain natural-height (no h-full/mt-auto/flex-1) — see [[Replace masonry panel layout with an explicit fixed-column + paired-row grid]]. If matching row heights is wanted again later, don't reach for h-full+flex-1+mt-auto — verify row height visually with a real screenshot before considering it done, this bug doesn't show up by reading the Blade diff alone.

## Facebook Lead Ads spreadsheet skeleton mirrors the main Leads table's pattern
Same skeleton-loading treatment as `leads.blade.php` (mobile card stack `display:contents` wrapper + skeleton sibling; desktop `<flux:table.row>`s individually hidden + a second `<flux:table.rows>` skeleton tbody), using `wire:loading.class="!hidden"` / `wire:loading.remove.class="!hidden"` throughout — never a bare `wire:loading`/`.remove` on a `<tbody>`/`<tr>`/`contents` div (see the rule in `resources-views-livewire-tenant.md` for why: Livewire's non-class display toggling falls back to `display:inline-block` for any display value outside its small supported list, breaking table row-group layout and, after one full loading cycle, permanently corrupting `.remove` elements).

Differences from the main table: `$listLoadingTargets` here is `status,priority,assignedTo,fromDate,toDate,search,gotoPage,previousPage,nextPage,setPage` — no `sourceType` (page is already scoped to one source) and no `setPerPage` (this page has no per-viewport page size). Skeleton row/card count uses a fixed `$sourceLeadsPerPage = 25` constant (matching `SourceLeads::leads()`'s hardcoded `->paginate(25)`) rather than a dynamic property, since none exists here. Desktop skeleton cell count per row is dynamic — 2 fixed cells (Lead ID, Name) + one per `$this->fieldColumns` (the Instant Form's variable field set) + 4 fixed (Form, Status, Submitted, Actions) — matching the real header's column count and the `@empty` row's `colspan`. If `fieldColumns` count logic changes, keep the skeleton's inner `@for` loop over `count($this->fieldColumns)` in sync.

## Lead detail compact cards share an auto-fit grid, not full-width stacking
Score, Deals, Tasks, Custom Fields, Consent and Notes sit in one `grid grid-cols-[repeat(auto-fit,minmax(min(100%,20rem),1fr))] items-start` in both show-panel.blade.php (flyout) and show.blade.php (Overview tab; the score card moved there from above the tabs). auto-fit sizes off the container, so it gives 1 column on phones, 2 on tablet and 2–3 on desktop, per the flyout's max-w. Calls and the message composer stay full-width below it. Inside those cards, use content-driven wrapping (flex-wrap, auto-fit grids) rather than sm:/md: viewport breakpoints, because a narrow grid cell on a wide screen still matches sm:. Don't add @container to the cards: it creates a stacking context and a containing block for fixed-position children, which can trap dropdowns and pickers. Don't use CSS columns for masonry either, since absolutely positioned popups get split across columns. The accepted trade-off is blank space under a short card that shares a row with a tall one.
