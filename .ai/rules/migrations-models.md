---
paths:
  - 'database/migrations/2026_09_02_16*.php,app/Models/Currency.php,tests/**'
---

# Migrations Models

## "USD" is a permanent baseline fixture in every test — never use it as a fresh/colliding fixture
The currencies-catalog migration (`2026_09_02_160000_create_currencies_table.php`) unconditionally seeds a "USD" row (so a fresh/empty DB — like the test DB — still has ≥1 currency for `AssignModuleToTenant::seedDefaultCurrency()`/`TenantFactory` to attach to new tenants). Because Pest uses `RefreshDatabase` (migrate once, wrap each test in a rolled-back transaction — see `tests/Pest.php`), that seeded "USD" row is a permanent baseline present in *every* test, not something an individual test creates.

Same trap already exists for `LeadStatus`'s 8 legacy-seeded statuses (`new`, `contacted`, etc. from `2026_09_02_000000_convert_lead_status_to_configurable_catalog.php`) — any test creating a "new" fixture with a colliding code/slug will hit a real unique-constraint DB error, not a clean validation failure. When writing a Currency (or LeadStatus) test that needs an "already exists" collision fixture, pick a code that isn't USD (e.g. JPY, GBP) — don't assume the catalog starts empty.
