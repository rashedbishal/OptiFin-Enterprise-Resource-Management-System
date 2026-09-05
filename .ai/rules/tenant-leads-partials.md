---
paths:
  - 'app/Models/LeadStatus.php,resources/views/livewire/tenant/leads/partials/pipeline-stepper.blade.php'
---

# Tenant Leads Partials

## Pipeline stepper's current-stage circle pulses in its own status color
Added `LeadStatus::pulseRingClasses()` (same match()-based safe-Tailwind-class pattern as `cardBackgroundClasses()`/`columnBackgroundClasses()` — a mid-tone bg-{color}-400/dark:bg-{color}-500 per hue) purely for the pipeline stepper's current-stage indicator, distinct from `alertShimmerRgb()` which is for the Kanban card's meeting/follow-up alert shimmer — these are two unrelated "attention" effects with two different color-mapping methods; don't conflate them. In pipeline-stepper.blade.php, only the current stage's circle gets an absolutely-positioned `motion-safe:animate-ping` ring behind it (classic Tailwind "ping badge" recipe: `relative` wrapper, `absolute` ping layer using `pulseRingClasses()`, then the actual circle `relative` so it paints on top via DOM order) — `motion-safe:` (not a bare `animate-ping`) so `prefers-reduced-motion: reduce` drops the animation entirely, same accessibility care as `.lead-id-shimmer-text`'s own reduced-motion override elsewhere in this module. This pulse is purely a "you are here" indicator on whichever stage matches `Lead::status_id` — it has nothing to do with meeting/follow-up alerts and fires regardless of whether `Lead::meetingAlertActive()`/`followUpAlertActive()` are true.
