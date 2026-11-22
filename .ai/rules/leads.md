---
paths:
  - 'app/Livewire/Tenant/Leads/**'
  - app/Livewire/Tenant/Leads/Index.php
  - app/Livewire/Tenant/Leads/Show.php
  - app/Livewire/Tenant/Leads/SourceLeads.php
  - 'app/Livewire/Tenant/Leads/{Index,Show}.php'
---

# Leads

## Facebook Lead Ads leads don't get individual rows in the Client Admin's table — but do on a Client User's Kanban board
See [[Facebook Lead Ads: aggregate row on the admin table, normal Kanban card for Client Users]] for the current, role-split behavior — this used to be a blanket exclusion from `Index` entirely, but no longer is.

For the Client Admin's table view, Facebook Lead Ads is still the deliberate exception: `Index::filteredQuery()` excludes leads whose source type is `facebook_lead_ads` when `$this->isClientAdmin` is true, and `Index::facebookLeadAdsSources()` instead surfaces one aggregate row per FacebookLeadAds LeadSource (name + lead count + last submission), always shown regardless of the status/priority/assigned-to dropdowns (they'd be meaningless applied to many leads at once) but still respecting non-admin agents' assignment-based visibility. Each aggregate row links to `tenant-leads.source` (App\Livewire\Tenant\Leads\SourceLeads, route `leads/sources/{leadSource}`), a spreadsheet-style view whose columns are the *union of every distinct Instant Form field name* found in that source's leads' `source_data['field_data']` (via `Lead::leadAdFieldValues()`) — not a fixed Name/Email/Phone shape, since different forms on the same Page can ask different questions. Clicking a row there still opens the normal `tenant-leads.show` Lead detail page; nothing about assignment/status/notes changes for these leads, only how they're browsed. Shared status/priority/assigned-to filtering and assignment-scoping logic between Index and SourceLeads lives in `App\Livewire\Tenant\Leads\Concerns\FiltersLeadsQuery`.

## Lead::lead_source is manual-entry-only metadata, distinct from the source() relation
`Lead::lead_source` (plain nullable string, e.g. "Referral", "Trade Show") is free-text descriptive metadata the sales rep types in — it has nothing to do with `Lead::source()`/`source_id`, which is the LeadSource relation every lead has (Manual/WhatsApp/WebsiteForm/etc.) recording which channel technically captured it.

It's only ever shown/editable on manual-entry leads (`Show::isManualLead()`, checks `$lead->source->type === LeadSourceType::Manual`), never on webhook/automated leads — gated both in the view (deal-form.blade.php's `@if ($isManual)`) and server-side in `Show::updateDeal()` (the column is only added to the `applyDetailChanges()` array when `isManualLead()` is true), so a tampered submission on a non-manual lead can't set it even if the field were somehow exposed.

`isManualLead()` is a Livewire `#[Computed]` method — call it as `$this->isManualLead()` from PHP code (not `$this->isManualLead` property-style access), since Larastan flags magic property access to Computed methods as an undefined-property error. Blade templates can keep using `$this->isManualLead` property-style (existing convention for `canUpdate`/`canAssign` etc.) since Larastan doesn't analyze compiled Blade the same way.

## Leads source filter is tenant-scoped, not a static enum list
`Index::sourceTypeOptions()` no longer returns every `LeadSourceType` case minus Facebook Lead Ads — it queries this tenant's actual `LeadSource` rows (`->distinct()->pluck('type')`) so the dropdown never offers a channel (LinkedIn, Instagram, ...) the tenant never connected, which used to always return zero rows if picked. `manual` is always force-included regardless of whether a Manual LeadSource row exists yet (it's lazily provisioned on first manual lead, see CreateManualLead). `facebook_lead_ads` follows the same "only if configured" rule but is still hard-excluded when `$this->isClientAdmin` is true, since `filteredQuery()` excludes that type outright for admins (see [[Facebook Lead Ads: aggregate row on the admin table, normal Kanban card for Client Users]]) — offering it there would always show zero rows.

## Show::assign() is a no-op when the assignee didn't actually change
Fixed a real bug: assign() used to unconditionally call LeadAssignmentService::assign()/unassign() every time it ran, even when $this->assigneeId already equaled the lead's current assigned_to. Since updateStatusAndAssignment() always calls assign() when canAssign() is true — regardless of whether the merged Status & Assignment form's save was actually about the assignee — every status/priority-only save on that form was silently re-triggering three side effects on an assignment that never changed: a duplicate LeadAssignment history row, LeadAssignedNotification firing again ("you've been assigned this lead" spam to someone already assigned), and assignee.last_assigned_at resetting to now() — corrupting LeadAssignmentService::pickRoundRobinUser()'s fairness ordering for future auto-distribution. assign() now early-returns (silently, same pattern as applyDetailChanges()'s own "did anything change" check) when the new assigneeId matches the current one. Real assignment changes are unaffected and still notify normally — this only suppresses the false-positive re-fire.

## SourceLeads eager-loads status too, not just assignedUser
Both leads() and downloadXlsx() now include 'status' in their eager loads — the Blade table and FacebookLeadAdsExport::map() both call $lead->status->color()/label() per row, and the original code only eager-loaded assignedUser, causing an N+1 (one query per row) on every page view and every export. If this file gains another per-row relation access, add it to both call sites' eager loads, not just leads() — the export uses a separate, unpaginated query.

## Lead delete() actions enforce the password themselves
Index::delete(), Show::delete() and Show::deleteConversation() are public Livewire actions, so they call ensureDeletePasswordConfirmed() (ConfirmsLeadDeletionPassword) after their authorization checks — the *Confirmed() entry point only marks the password verified for that request (private, non-dehydrated flag). This supersedes the "real action keeps its signature so tests can call it directly" convention for lead deletes: tests calling delete()/deleteConversation() directly must ->set('deletePassword', 'password') first.
