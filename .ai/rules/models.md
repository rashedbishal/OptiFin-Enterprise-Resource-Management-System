---
paths:
  - 'app/Models/{PlatformUser,TenantUser}.php'
  - 'app/Models/{LeadAssignment,LeadNote,LeadStatusLog,LeadDetailUpdateLog}.php'
---

# Models

## New mutable column on PlatformUser/TenantUser? Add it to #[Fillable] too
These two models use the `#[Fillable([...])]` PHP attribute instead of a `protected $fillable` property. It's easy to add a migration column (and even a cast/property) without remembering to also add it to the attribute list — `->update([...])` then silently drops that field with no error. This already happened once: `is_active` was missing from both models' Fillable list, so the existing "toggle active/inactive" Livewire actions (`Platform\Admins\Index::toggleActive`, `Tenant\Users\Index::toggleActive`) were silently no-ops until a Lead Management auto-assignment test caught it. When adding a column meant to be mass-assigned via `create()`/`update()`, always check `#[Fillable(...)]` on the model, not just the migration.

## LeadAssignment/LeadNote/LeadStatusLog/LeadDetailUpdateLog now use ULID primary keys
All four now use HasUlids + HasFactory (factories added: LeadAssignmentFactory, LeadNoteFactory, LeadStatusLogFactory, LeadDetailUpdateLogFactory), matching every other model in the Lead Management module — they were previously the only ones on auto-increment bigint IDs. Migrated via database/migrations/2026_09_05_082339_convert_lead_log_tables_to_ulid_primary_keys.php, which rebuilds each table under a new name (CREATE + copy-with-regenerated-ulid + DROP + RENAME) rather than altering the `id` column in place — SQLite's schema diffing refuses to ever drop a column that was an INTEGER PRIMARY KEY (its rowid alias) even after the PK constraint is dropped and the column renamed out of the way first, so the simpler in-place ALTER approach (which works fine on Postgres) had to be replaced with this fully-portable rebuild. If any of these four tables ever need another structural migration, prefer the same create+copy+swap pattern over in-place ALTERs if it touches the primary key column specifically.
