---
paths:
  - 'app/Livewire/Tenant/Leads/SourceLeads.php,resources/views/livewire/tenant/leads/source-leads.blade.php'
---

# Views Livewire Tenant Leads

## Facebook Lead Ads spreadsheet: mobile card view + desktop shimmer (previously neither existed)
The per-source spreadsheet page (`/leads/sources/{leadSource}`, SourceLeads::class) used to render only a horizontally-scrolling `<flux:table>` at every viewport, and its Lead ID cell never computed the meeting/follow-up alert flags at all — no shimmer existed here, unlike the main Leads table. Fixed both: (1) added a `sm:hidden` mobile card stack (mirroring the main Leads page's card pattern) showing ONLY Lead ID, Status badge, and Submitted time — deliberately not Name, the dynamic Instant Form field columns, or Form, since a card can't usefully show a variable number of per-form fields; the whole card is a real `<a href="{{ route('tenant-leads.show', $lead) }}" wire:navigate>`, no separate View button. (2) Wrapped the existing table in `hidden sm:block` and added the same `$rowMeetingAlertActive`/`$rowFollowUpAlertActive`/`$rowHasActiveAlert` + `.lead-id-shimmer-text` + tooltip treatment used on the main Leads table's desktop Lead ID cell — both the new mobile card and the desktop table now shimmer identically. Same caveat as [[Mobile card stack must mirror the desktop table's Lead ID treatment (shimmer + non-muted color)]]: these are two independent markup copies in the same file, not a shared partial — a future change to one must be mirrored into the other by hand.
