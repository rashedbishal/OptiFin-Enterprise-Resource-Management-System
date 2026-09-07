---
paths:
  - 'app/Models/LeadStatus.php,resources/views/livewire/tenant/leads/partials/pipeline-stepper.blade.php'
  - 'app/Models/Lead.php,resources/views/livewire/tenant/leads/partials/detail-card.blade.php'
---

# Tenant Leads Partials

## Pipeline stepper's current-stage circle pulses in its own status color
Added `LeadStatus::pulseRingClasses()` (same match()-based safe-Tailwind-class pattern as `cardBackgroundClasses()`/`columnBackgroundClasses()` — a mid-tone bg-{color}-400/dark:bg-{color}-500 per hue) purely for the pipeline stepper's current-stage indicator, distinct from `alertShimmerRgb()` which is for the Kanban card's meeting/follow-up alert shimmer — these are two unrelated "attention" effects with two different color-mapping methods; don't conflate them. In pipeline-stepper.blade.php, only the current stage's circle gets an absolutely-positioned `motion-safe:animate-ping` ring behind it (classic Tailwind "ping badge" recipe: `relative` wrapper, `absolute` ping layer using `pulseRingClasses()`, then the actual circle `relative` so it paints on top via DOM order) — `motion-safe:` (not a bare `animate-ping`) so `prefers-reduced-motion: reduce` drops the animation entirely, same accessibility care as `.lead-id-shimmer-text`'s own reduced-motion override elsewhere in this module. This pulse is purely a "you are here" indicator on whichever stage matches `Lead::status_id` — it has nothing to do with meeting/follow-up alerts and fires regardless of whether `Lead::meetingAlertActive()`/`followUpAlertActive()` are true.

## Website Form leads: arbitrary extra fields shown via "Additional Details"
A client's contact form can have any number of fields under any names — LeadWebhookController::websiteForm() already stores everything beyond name/email/phone/message straight into source_data (no wrapping sub-key, unlike Facebook Lead Ads' field_data), so for a Website Form lead source_data in its entirety already IS its extra-field list.

Lead::websiteFormFieldValues() reads that and normalizes each value to a display string (arrays comma-join; other non-scalars fall back to json_encode) so Blade never chokes on an array-to-string cast. Lead::isWebsiteForm() gates a new "Additional Details" block in detail-card.blade.php, appended AFTER the existing conversation/Facebook-fields/plain-message @if/@elseif/@endif chain (not another @elseif branch in it) — a Website Form lead's own `message` field (if its form has one) still renders via the existing plain-message branch, and this is complementary structured data alongside it, not a replacement.

Field labels are title-cased from the raw key (underscores AND hyphens both treated as word breaks: ucwords(str_replace(['_','-'], ' ', $field))) since a client's form field names are arbitrary and unknowable in advance — same rendering as leadAdFieldValues()'s grid, reused verbatim (same dt/dd markup) for visual consistency. Shared by both show.blade.php and show-panel.blade.php's Kanban flyout, like every other card in this partial — see [[Re-shimmering skeletons on tab switch needs a distinct wire:key per branch]] for the $panel-conditional skeleton wiring this block sits inside (unaffected — it's gated purely on isWebsiteForm(), same wrapper div).
