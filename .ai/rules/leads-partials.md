---
paths:
  - resources/views/livewire/tenant/leads/partials/pipeline-stepper.blade.php
  - resources/views/livewire/tenant/leads/partials/lead-hero.blade.php
  - 'resources/views/livewire/tenant/leads/partials/{deals-card,tasks-card,notes-card}.blade.php'
  - resources/views/livewire/tenant/leads/partials/notes-card.blade.php
  - resources/views/livewire/tenant/leads/partials/calls-card.blade.php
  - 'resources/views/livewire/tenant/leads/partials/{messenger-chat-card,detail-card}.blade.php'
---

# Leads Partials

## Pipeline stepper: numbered steps + chevron connectors in a wrapping flex row, never a scrollbar
The "Status Pipeline Progression" stepper went through three earlier designs before landing here — a single `flex gap-2 overflow-x-auto` row (scrolled on mobile), a grid split at an `lg` cutoff with a horizontal row surviving above it (still scrolled on wide desktop), then a single responsive `grid` at every breakpoint (no scrollbar anywhere, but the user wanted an actual connected "pipeline" look with numbers, not a grid of disconnected boxes). Final design: each stage is a `[numbered circle + label]` unit (`flex max-w-40 items-center gap-2`), separated by a `<flux:icon.chevron-right>` connector — the same connector visual language `<x-breadcrumbs>` already uses elsewhere in this app. The whole thing sits in one `flex flex-wrap items-center gap-y-3` container: each unit wraps onto the next line as a whole once it doesn't fit, so there's never a scrollbar at any viewport regardless of how many stages a tenant's pipeline has. `max-w-40` on each unit means an unusually long custom status name wraps inside its own step instead of pushing the row wider. Don't reintroduce a `flex`/`grid` + `overflow-x-auto` scrollable row for this, at any breakpoint — flex-wrap is what makes the no-scrollbar guarantee hold regardless of pipeline length or screen width.

The number circle itself carries the stage's ordinal, so there's no separate "Stage N" caption any more (it would just repeat what the circle already shows). Current stage: `LeadStatus::cardBackgroundClasses()` + `companyNameTextClasses()` on the circle (same status-color pairing already used elsewhere for "current" surfaces); every other stage is a plain outlined zinc circle — no completed/upcoming distinction is drawn (no checkmarks, no "past" styling), since that was never asked for, only the current stage needs to stand out.

Flux icons compile to inline SVG with no icon-name string surviving in the output (e.g. `<svg data-flux-icon ...>`, not literally "chevron-right") — a test asserting the connector count must match on one of the icon's own distinguishing utility classes, not the icon name.

## Lead hero: Back to Leads routes FB Lead Ads leads to their source, and is compact on mobile
"Back to Leads" used to always route to `tenant-leads.index`, but a Facebook Lead Ads lead never appears as a row there at all (Index::filteredQuery() excludes that type outright for admins — it only shows as one aggregate card per source, linking to `tenant-leads.source`). `$backToLeadsUrl` now branches on `$lead->source->type === LeadSourceType::FacebookLeadAds`: that type goes to `route('tenant-leads.source', $lead->source)`, everything else keeps `route('tenant-leads.index')` as before. `$lead->source` is accessed lazily here (not eager-loaded in Show::mount()) — already an accepted pattern on this page (pipeline-attributes-card.blade.php, detail-card.blade.php do the same), fine for a single-record detail page.

Also: the hero's contact/company name used `flux:heading size="xl"` (fixed `text-2xl` at every viewport, too large for a compact mobile header) — replaced with a plain `<p class="text-lg font-medium ... sm:text-2xl ...">` rather than fighting the Flux prop with an added class, since a consumer-passed class and a component-computed class of the same Tailwind utility family don't reliably cascade in a predictable order (same reasoning as the "Stage :n" caption fix elsewhere in this module: bypass the size prop entirely with a plain element instead of trying to override it). Card padding/gaps/avatar size also shrink at `sm:` (e.g. `p-3 sm:p-5`, `size-9 sm:size-11`) for the same compact-mobile-header ask; desktop is otherwise unchanged.

## Pipeline stepper skeleton must not reuse the real chevron's exact classes
pipeline-stepper.blade.php now has the same real/skeleton toggle pair as lead-hero.blade.php ($listLoadingTargets = 'completeInitialLoad', $showSkeletonByDefault = ! $initialLoadComplete) — full-page-only like lead-hero (never included by show-panel.blade.php's flyout), so it's keyed off the page's one-time $initialLoadComplete, not $tabContentReady; it sits above the tabs and isn't tab content.

Trap: the skeleton's connector placeholder must NOT reuse flux:icon.chevron-right's real classes ("mx-2 size-4 shrink-0 text-zinc-300 dark:text-zinc-600") — both skeleton and real content render into the same html() output simultaneously (only CSS-hidden, never removed from the DOM), so PipelineStepperTest's `substr_count($html, 'mx-2 size-4 shrink-0 text-zinc-300')` count would include the skeleton's connectors too and fail. Skeleton uses a plain shimmer block with different classes instead.

## Pipeline stepper: content-sized stages (flex-auto) + wrapping labels, chevron outside the pill
Two regressions to keep fixed together. (1) Chevron overlap (fixed in 10bc8ed): the chevron is a shrink-0 sibling of the pill inside each stage unit, never inside the pill or on a fixed-width (w-[calc(50%…)]) unit. (2) Truncated labels: units used sm:flex-1 (basis 0) → every stage forced to the same 130–220px, leaving ~50px for the label, so `truncate` cut it off. Units are now `sm:flex-auto sm:min-w-[130px] sm:max-w-[280px]` (basis = content, so a stage wraps to the next line whole before it shrinks) and the label is `min-w-0 leading-snug wrap-break-word` (never `truncate`) so an over-long custom status wraps inside its own pill. Don't go back to flex-1 or truncate. Verified with Playwright at 1280/900/700/390px: no chevron-over-pill overlap, no clipped label.

## Cap unbounded compact-card lists
Deals, Tasks, and Notes are unbounded lists shared by the client-user flyout and client-admin detail page. Keep each list body at max-h-96 with overflow-y-auto and overscroll-contain, leaving the card header/actions outside the scroll region so additions cannot grow the detail surface indefinitely.

## Notes need a larger viewport-aware scroll area
Supersedes the Notes portion of “Cap unbounded compact-card lists”: notes remain internally scrollable, but use max-h-[min(36rem,calc(100dvh-12rem))] so a note's content is readable without allowing the card to grow beyond the viewport. Deals and Tasks retain max-h-96.

## Calls card uses only implemented inbound-call state
The Calls card must use the existing LeadCall status/claimant data and direct WhatsApp lead source check. Do not reference outbound-call permissions, callable WhatsApp source resolvers, CallDirection, or associated Livewire actions unless their model, resolver, enum, and component API are implemented together; dangling references prevent every lead detail page from rendering.

## Notes need large fixed list and tile dimensions
Supersedes “Notes need a larger viewport-aware scroll area”: the viewport-aware cap made Notes too short in flyouts. Keep the list at max-h-[48rem] with internal scrolling, and each note tile at min-h-32 so its content has a readable area. Deals and Tasks remain max-h-96.

## Compute $lead->messageTimeline() once per render, never call it twice
Lead::messageTimeline() is a plain model method, not a Livewire #[Computed] property — nothing memoizes it, so every call re-runs its query. Both detail-card.blade.php and messenger-chat-card.blade.php used to call it twice in their top @php block (once for $hasConversation, then again inside trans_choice()'s two count() args — three calls total per render once you count both). Fixed by computing `$timelineMessageCount = count($lead->messageTimeline())` once and reusing that variable everywhere in the partial, including both trans_choice() args.

Confirmed via a DB::listen query-count diagnostic on Show::sendMessage(): collapsed this query from 4x to 2x per render (the remaining 2x is Show::timeline()'s own, separate, legitimate call — merges in attachments on top of messageTimeline(), see [[Deliberately NOT done: Show::timeline()]] in show-paneltenant-leads-partials.md), plus an extra 2-query drop in a downstream tenant_users sentBy eager-load that was tied 1:1 to each redundant messageTimeline() collection fetch. Zero behavior change — same data, computed once instead of three times. If you add a new usage of $lead->messageTimeline() to either partial, read the existing $timelineMessageCount (or $hasConversation) variable instead of calling the method again.
