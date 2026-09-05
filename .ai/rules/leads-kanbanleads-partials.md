---
paths:
  - 'app/Livewire/Tenant/Leads/**,resources/views/livewire/tenant/{leads-kanban,leads/partials/detail-card}.blade.php'
---

# Leads Kanbanleads Partials

## Facebook Lead Ads: aggregate row on the admin table, normal Kanban card for Client Users
Updates [[Facebook Lead Ads leads don't get individual rows in /leads]]: that exclusion now only applies to the Client Admin's table view. `Index::filteredQuery()` excludes source type facebook_lead_ads only when `$this->isClientAdmin` is true — a Client User's Kanban board (boardLeads()/boardLeadCounts(), which share filteredQuery()) shows their assigned Facebook Lead Ads leads as normal cards in the pipeline column, same as every other source type. The old "Facebook Lead Ads" aggregate box above the board in leads-kanban.blade.php was removed (it only ever showed sources with leads_count > 0 for non-admins anyway, so nothing is lost); the admin table's aggregate row + "View All" link to the spreadsheet view (SourceLeads) is untouched. Since these leads have no fixed contact-form shape, the shared partials/detail-card.blade.php (used by both show.blade.php and show-panel.blade.php, per [[Full-page Lead view (show.blade.php) has its own dedicated partials]]) renders `Lead::leadAdFieldValues()` as a label/value grid when `Lead::isFacebookLeadAd()` is true, instead of the normal message/attachment feed.
