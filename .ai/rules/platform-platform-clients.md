---
paths:
  - 'app/{Actions/Platform/DeleteTenant.php,Policies/TenantPolicy.php,Livewire/Platform/Clients/Show.php}'
---

# Platform Platform Clients

## Permanent tenant deletion is Mother-Admin-only, two passwords, deactivated-first
Shipped 2026-10-12, framed in the UI as "delete the Client Admin" but actually deletes the whole Tenant row — every tenant-scoped table cascades at the DB level (confirmed: every tenant_id FK in this schema is cascadeOnDelete or nullOnDelete), so $tenant->delete() alone wipes every row. TenantPolicy::delete() is deliberately NOT permission-based like its sibling abilities — `return $actor->isMotherAdmin();` — no other Platform Admin can ever do this regardless of client-management grants.

Preconditions enforced in Platform\Clients\Show::confirmDeleteTenant()/deleteTenantStep2Confirmed() (re-checked at both ends, same discipline as Lead deletion elsewhere): the tenant's Client Admin must already be deactivated via [[Client Admin self-action lockout + Platform cascade activation]]'s ManageClientAdminActivation — deletion refuses otherwise. Two SEPARATE password fields/modals (deleteTenantPassword1/2), not the shared `<x-confirm-password-delete-modal>` component (which hardcodes a single "deletePassword" field already used for lead deletion) — custom modal markup instead, to avoid touching that shared component for everyone else.

App\Actions\Platform\DeleteTenant is a pure action (no auth/precondition checks inside it — the Livewire component owns all of that, same separation as ManageClientAdminActivation) that does the one thing cascade deletes can never do: before calling $tenant->delete(), it reads every tenant-owned file path still on disk (lead_attachments.path via a leads subquery — lead_attachments has no tenant_id column directly; messages.attachment_path and lead_imports.file_path, both of which DO have tenant_id directly; tenants.whatsapp_call_ringtone_path) and deletes them from the `local` disk immediately after the DB transaction commits — not deferred to leads:cleanup-attachments' periodic sweep (that command exists for the OLD gap this closes: a lead-source/tenant cascade used to leave files orphaned until the next sweep ran). If a new tenant-scoped table ever gains its own file-storage column, add it to DeleteTenant::collectStoragePaths() or this "clean deletion" guarantee quietly breaks.
