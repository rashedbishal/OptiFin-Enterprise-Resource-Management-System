---
paths:
  - 'app/Livewire/Tenant/Leads/**'
---

# Leads

## Facebook Lead Ads leads don't get individual rows in /leads
Every other LeadSourceType shows one row per Lead in the main `/leads` index (App\Livewire\Tenant\Leads\Index). Facebook Lead Ads is the deliberate exception: `Index::filteredQuery()` excludes leads whose source type is `facebook_lead_ads`, and `Index::facebookLeadAdsSources()` instead surfaces one aggregate row per FacebookLeadAds LeadSource (name + lead count + last submission), always shown regardless of the status/priority/assigned-to dropdowns (they'd be meaningless applied to many leads at once) but still respecting non-admin agents' assignment-based visibility. Each aggregate row links to `tenant-leads.source` (App\Livewire\Tenant\Leads\SourceLeads, route `leads/sources/{leadSource}`), a spreadsheet-style view whose columns are the *union of every distinct Instant Form field name* found in that source's leads' `source_data['field_data']` (via `Lead::leadAdFieldValues()`) — not a fixed Name/Email/Phone shape, since different forms on the same Page can ask different questions. Clicking a row there still opens the normal `tenant-leads.show` Lead detail page; nothing about assignment/status/notes changes for these leads, only how they're browsed. Shared status/priority/assigned-to filtering and assignment-scoping logic between Index and SourceLeads lives in `App\Livewire\Tenant\Leads\Concerns\FiltersLeadsQuery`.
