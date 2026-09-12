---
paths:
  - 'app/{Models/LeadTag.php,Livewire/Tenant/LeadTags/**,Policies/LeadTagPolicy.php}'
---

# Lead Tags

## LeadTag is a tenant-owned catalog, not a Platform-Admin shared one
Unlike LeadStatus/Currency (Platform-Admin-managed, shared across tenants via a pivot), LeadTag carries tenant_id directly and has no BelongsToTenant trait — every query filters tenant_id explicitly (same discipline as LeadScoringRule). Same tag name/slug can exist independently in different tenants; unique(tenant_id, slug) is the only constraint. Catalog CRUD is Client-Admin-only (App\Policies\LeadTagPolicy, reuses lead-management "update" + isClientAdmin(), no new PermissionSlug), but attaching/detaching a tag on a lead reuses the lighter LeadPolicy::update() bar (same as adding a LeadNote) — don't conflate the two authorization levels.
