---
paths:
  - 'app/Models/{PlatformUser,TenantUser}.php'
---

# Models

## New mutable column on PlatformUser/TenantUser? Add it to #[Fillable] too
These two models use the `#[Fillable([...])]` PHP attribute instead of a `protected $fillable` property. It's easy to add a migration column (and even a cast/property) without remembering to also add it to the attribute list — `->update([...])` then silently drops that field with no error. This already happened once: `is_active` was missing from both models' Fillable list, so the existing "toggle active/inactive" Livewire actions (`Platform\Admins\Index::toggleActive`, `Tenant\Users\Index::toggleActive`) were silently no-ops until a Lead Management auto-assignment test caught it. When adding a column meant to be mass-assigned via `create()`/`update()`, always check `#[Fillable(...)]` on the model, not just the migration.
