---
paths:
  - 'app/{Livewire/Tenant/Leads,Policies,Exports}/**'
---

# Leads Policies Exports

## Facebook Lead Ads: delete-when-empty and XLSX export
Two more deliberate exceptions on top of the aggregate-row behavior in [[Facebook Lead Ads leads don't get individual rows in /leads]]:

1. **Delete-when-empty**: the aggregate row on `/leads` shows "View All" when `leads_count > 0`, or "Delete" when it's 0 (Client Admins only) — never both. `Index::deleteSource()` re-checks `leads()->exists()` server-side before deleting (the UI hiding the button isn't enough on its own) and flashes a danger toast instead of deleting if leads exist. This required adding `LeadSourcePolicy::delete()` — Lead Sources are otherwise still Platform-Admin-managed/read-only on the tenant side (see the class docblock); this is the one deliberate mutation exception, scoped to a source with zero leads.

2. **XLSX export**: `SourceLeads::downloadXlsx()` uses `maatwebsite/excel` (added for this — first Excel dependency in the app) via `App\Exports\FacebookLeadAdsExport` (`FromCollection`/`WithHeadings`/`WithMapping`). Its columns mirror the on-screen spreadsheet exactly: Name, then one column per `fieldColumns()` entry, then Form/Status/Submitted At — reuses `Lead::leadAdFieldValues()` and the same `applyDropdownFilters()` scope as the table, just unpaginated, so the download always matches what's currently shown.
