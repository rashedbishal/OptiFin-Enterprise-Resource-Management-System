---
paths:
  - 'app/Models/{Lead,Tenant}.php,app/Actions/Platform/CreateClient.php,database/factories/TenantFactory.php'
---

# Factories

## Lead identifiers: {tenant.lead_id_prefix}-LEAD-{n}, generated in Lead::booted()
Every Lead gets a human-readable `identifier` (e.g. `OPTICORE-LEAD-1`), auto-generated in `Lead::booted()`'s `creating` hook — never mass-assignable (deliberately left out of `#[Fillable]`, same trap as elsewhere in this app). The prefix is `Tenant::lead_id_prefix`, computed once by `Tenant::generateLeadIdPrefix()` when the tenant is created (`CreateClient`, and `TenantFactory` for tests) and frozen forever after: just the tenant name's **first word**, uppercased/slugified ("Opticore Solutions Ltd" → `OPTICORE`, not the whole name; "Acme" → `ACME` when the name is already one word) — deduped against other tenants' prefixes (appends `-2`, `-3`, ...) since two tenants' names can start with the same word. The per-tenant counter is `Tenant.next_lead_number`, incremented under `lockForUpdate()` inside a `DB::transaction()` in `Lead::nextIdentifier()` so concurrent lead creation (webhook bursts) never collides. Numbers are monotonic only — deleting a lead never reclaims its number. Every Lead::create() call site (manual, email, webhook) goes through this same hook automatically; nothing extra is needed when adding a new lead-creation path. Existing pre-migration leads/tenants were backfilled in `database/migrations/2026_09_03_071412_*` and `2026_09_03_071413_*`; a later migration, `2026_09_03_072924_use_first_word_for_lead_id_prefix.php`, corrected the prefix from "whole name" to "first word only" — if this rule changes again, add another corrective migration rather than editing either of those.
