---
paths:
  - 'resources/views/livewire/tenant/leads/**'
---

# Tenant Leads

## Don't combine flex-1 + h-full inside a CSS Grid row with align-items:stretch
Tried twice in the lead panel's Contact/Company/Status/Note cards: giving a card `h-full` + its form `flex h-full flex-col`, with a `flex-1` on an inner field (e.g. a textarea) so a trailing button could sit at `mt-auto`, to make row-mates in a CSS Grid match height.

Both times it backfired — the `flex-1` child's intrinsic-size contribution to the grid's auto row-height calculation gets wildly inflated (a circular sizing dependency between the flex-grow item and the indefinite/percentage-height ancestor), so the whole row's height balloons and every card in it gets stretched to match, leaving 300+px of dead space between the fields and the button ("button looks like it's outside the card").

Current fix: these 4 cards are plain natural-height (no h-full/mt-auto/flex-1) — see [[Replace masonry panel layout with an explicit fixed-column + paired-row grid]]. If matching row heights is wanted again later, don't reach for h-full+flex-1+mt-auto — verify row height visually with a real screenshot before considering it done, this bug doesn't show up by reading the Blade diff alone.
