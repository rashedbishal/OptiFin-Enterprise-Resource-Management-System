---
paths:
  - 'app/{Models,Http/Middleware,Policies}/**'
---

# Middleware Policies

## Dual-guard SaaS auth: platform_users vs tenant_users, never a shared "User"
This app has two completely separate people-tables/guards, chosen deliberately over a single `users` table so a client user's row physically cannot exist in the platform table:
- `App\Models\PlatformUser` (table `platform_users`, guard `platform`, routes under `/admin`) — Mother Admin (`user_type=mother_admin`, exactly one, enforced by a Postgres/SQLite partial unique index, bypasses all authorization via `Gate::before`) + Platform Admins.
- `App\Models\TenantUser` (table `tenant_users`, guard `web`, default Fortify-powered `/login`) — Client Admins + Client Users, always scoped to a `tenant_id`.
Both share one Role→Module→Permission engine (`roles`/`modules`/`permissions`/`role_modules`/`role_permissions`/`tenant_modules`, see `App\Services\RoleService` and `App\Concerns\HasModulePermissions`). `modules.scope` (platform|tenant) gates which catalog a role can draw from. All IDs are ULIDs. Do not reintroduce a single generic `User` model — extend `PlatformUser`/`TenantUser` and their existing policies/observers instead.
