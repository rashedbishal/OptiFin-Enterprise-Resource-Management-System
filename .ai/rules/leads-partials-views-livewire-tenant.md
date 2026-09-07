---
paths:
  - 'resources/views/livewire/tenant/leads/{show,show-panel}.blade.php,resources/views/livewire/tenant/leads/partials/{lead-hero,contact-info-card,pipeline-attributes-card,detail-card,note-form,status-assignment-form}.blade.php,resources/views/livewire/tenant/lead-sources.blade.php'
---

# Leads Partials Views Livewire Tenant

## Lead detail page: every card shimmers together on load, even ones with nothing to defer
Extends the Activity-feed-only skeleton (previous rule in `.ai/rules/show-paneltenant-leads-partials.md`) to every other card on the full-page lead detail view: the top hero card (`lead-hero.blade.php`), Contact Information, Pipeline Attributes, Details (`detail-card.blade.php` — the one flagged as unsafe to touch in the earlier rule; safe now, see below), Add Note, and Status & Assignment.

Key difference from Activity: none of these cards have a real query to defer — `$lead` (and everything read off it: phone, email, `assignedUser`, `messageTimeline()`, `attachments`, etc.) is already loaded synchronously by the time Show renders, since it's route-model-bound before `mount()` even runs. So these cards do NOT gate any data behind `$initialLoadComplete` — they only gate their own **visibility** (`$showSkeletonByDefault`/`wire:loading.class="!hidden"`, self-computed locally per partial, same as `activity-card.blade.php`), purely so the whole page reveals together on first paint instead of these cards popping in ahead of the genuinely-async Activity feed. Skeleton row/line counts are all generic placeholders, not tied to any real data shape.

This is what makes `detail-card.blade.php` (the message/attachment conversation feed) safe to add a skeleton to now, unlike the earlier attempt: that attempt tried to gate the *data* (`$this->timeline`), which broke because the card's outer `@if` branches on `$lead->messageTimeline()` (the raw model method) while the inner loop iterates `$timeline` (the computed property) — gating the latter alone left the `@if` still true while iterating an empty array, a blank box instead of a skeleton. This time, NONE of that branching logic was touched — only the whole card's outer visibility toggles, wrapping ALL of its existing conditional branches (conversation feed / Facebook Instant Form fields / email body / plain message) behind one generic skeleton. If a future defer of `timeline()`'s actual query is wanted for a genuine performance reason, that outer `@if` still needs to move in lockstep with whatever gates the data — this fix didn't solve that, it sidestepped needing to.

Not touched: `contact-form`/`company-form`/`deal-form` (the manual-lead editing equivalents of Contact Information/Pipeline Attributes, used when `Show::isManualLead()` is true) — out of scope unless asked, since they're structurally different (already-editable forms with their own edit-toggle state).

`resources/views/livewire/tenant/lead-sources.blade.php` (Client Admin's read-only Lead Sources table) got a `sm:hidden` mobile card stack alongside its existing `hidden sm:block` desktop table — it used to be a single always-rendered table that scrolled horizontally on a phone (4 columns including a webhook URL). Both the real and skeleton content exist in both layouts, following the same established pair-per-breakpoint pattern as the main Leads table.
