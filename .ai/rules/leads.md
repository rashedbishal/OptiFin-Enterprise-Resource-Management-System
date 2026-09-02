---
paths:
  - 'app/Livewire/Tenant/Leads/**'
---

# Leads

## Facebook Lead Ads leads don't get individual rows in /leads
Every other LeadSourceType shows one row per Lead in the main `/leads` index (App\Livewire\Tenant\Leads\Index). Facebook Lead Ads is the deliberate exception: `Index::filteredQuery()` excludes leads whose source type is `facebook_lead_ads`, and `Index::facebookLeadAdsSources()` instead surfaces one aggregate row per FacebookLeadAds LeadSource (name + lead count + last submission), always shown regardless of the status/priority/assigned-to dropdowns (they'd be meaningless applied to many leads at once) but still respecting non-admin agents' assignment-based visibility. Each aggregate row links to `tenant-leads.source` (App\Livewire\Tenant\Leads\SourceLeads, route `leads/sources/{leadSource}`), a spreadsheet-style view whose columns are the *union of every distinct Instant Form field name* found in that source's leads' `source_data['field_data']` (via `Lead::leadAdFieldValues()`) — not a fixed Name/Email/Phone shape, since different forms on the same Page can ask different questions. Clicking a row there still opens the normal `tenant-leads.show` Lead detail page; nothing about assignment/status/notes changes for these leads, only how they're browsed. Shared status/priority/assigned-to filtering and assignment-scoping logic between Index and SourceLeads lives in `App\Livewire\Tenant\Leads\Concerns\FiltersLeadsQuery`.

## Lead::lead_source is manual-entry-only metadata, distinct from the source() relation
`Lead::lead_source` (plain nullable string, e.g. "Referral", "Trade Show") is free-text descriptive metadata the sales rep types in — it has nothing to do with `Lead::source()`/`source_id`, which is the LeadSource relation every lead has (Manual/WhatsApp/WebsiteForm/etc.) recording which channel technically captured it.

It's only ever shown/editable on manual-entry leads (`Show::isManualLead()`, checks `$lead->source->type === LeadSourceType::Manual`), never on webhook/automated leads — gated both in the view (deal-form.blade.php's `@if ($isManual)`) and server-side in `Show::updateDeal()` (the column is only added to the `applyDetailChanges()` array when `isManualLead()` is true), so a tampered submission on a non-manual lead can't set it even if the field were somehow exposed.

`isManualLead()` is a Livewire `#[Computed]` method — call it as `$this->isManualLead()` from PHP code (not `$this->isManualLead` property-style access), since Larastan flags magic property access to Computed methods as an undefined-property error. Blade templates can keep using `$this->isManualLead` property-style (existing convention for `canUpdate`/`canAssign` etc.) since Larastan doesn't analyze compiled Blade the same way.
