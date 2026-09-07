---
paths:
  - 'resources/views/livewire/tenant/leads/partials/{lead-card,header}.blade.php,resources/views/livewire/tenant/leads-kanban.blade.php'
---

# Partials Views Livewire Tenant

## Kanban card badges & board search bar
`partials/status-badges.blade.php` was deleted — its status+priority badge pair is now inlined directly into `header.blade.php` (used by `show-panel.blade.php`), sitting above the lead name next to the identifier. Don't recreate it as a standalone partial.

`lead-card.blade.php` (Kanban board card) badges deliberately use `!px-1.5` (important, overrides Flux's default badge padding) with `text-[9px] leading-none` so the identifier + priority pills fit on one line without wrapping; identifier badge is additionally `font-mono font-bold`. Keep this sizing when touching Kanban card badges.

`leads-kanban.blade.php`'s search input (`wire:model.live.debounce.400ms="search"`) is case-insensitive and only matches Lead ID, contact name, phone, company, email — see `FiltersLeadsQuery::applySearchFilter()`. It's `size="sm"` to match `x-dropdown-select`'s button height, and `col-span-2 md:col-span-1` so it fills the row alone on the 2-col mobile filter grid (instead of sitting half-width next to empty space), then drops to a normal single cell on md/lg's 3-col grid (priority + sourceType + search).
