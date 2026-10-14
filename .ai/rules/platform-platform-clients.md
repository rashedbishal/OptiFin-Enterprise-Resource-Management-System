---
paths:
  - 'app/{Actions/Platform/DeleteTenant.php,Policies/TenantPolicy.php,Livewire/Platform/Clients/Show.php}'
  - 'app/{Actions/Platform/CreateCallPermissionRequestTemplate.php,Livewire/Platform/Clients/LeadSources.php},config/calling.php'
---

# Platform Platform Clients

## Permanent tenant deletion is Mother-Admin-only, two passwords, deactivated-first
Shipped 2026-10-12, framed in the UI as "delete the Client Admin" but actually deletes the whole Tenant row — every tenant-scoped table cascades at the DB level (confirmed: every tenant_id FK in this schema is cascadeOnDelete or nullOnDelete), so $tenant->delete() alone wipes every row. TenantPolicy::delete() is deliberately NOT permission-based like its sibling abilities — `return $actor->isMotherAdmin();` — no other Platform Admin can ever do this regardless of client-management grants.

Preconditions enforced in Platform\Clients\Show::confirmDeleteTenant()/deleteTenantStep2Confirmed() (re-checked at both ends, same discipline as Lead deletion elsewhere): the tenant's Client Admin must already be deactivated via [[Client Admin self-action lockout + Platform cascade activation]]'s ManageClientAdminActivation — deletion refuses otherwise. Two SEPARATE password fields/modals (deleteTenantPassword1/2), not the shared `<x-confirm-password-delete-modal>` component (which hardcodes a single "deletePassword" field already used for lead deletion) — custom modal markup instead, to avoid touching that shared component for everyone else.

App\Actions\Platform\DeleteTenant is a pure action (no auth/precondition checks inside it — the Livewire component owns all of that, same separation as ManageClientAdminActivation) that does the one thing cascade deletes can never do: before calling $tenant->delete(), it reads every tenant-owned file path still on disk (lead_attachments.path via a leads subquery — lead_attachments has no tenant_id column directly; messages.attachment_path and lead_imports.file_path, both of which DO have tenant_id directly; tenants.whatsapp_call_ringtone_path) and deletes them from the `local` disk immediately after the DB transaction commits — not deferred to leads:cleanup-attachments' periodic sweep (that command exists for the OLD gap this closes: a lead-source/tenant cascade used to leave files orphaned until the next sweep ran). If a new tenant-scoped table ever gains its own file-storage column, add it to DeleteTenant::collectStoragePaths() or this "clean deletion" guarantee quietly breaks.

## Platform Admin button creates the per-WABA call_permission_request template
Shipped 2026-10-14 after a real production gap: RequestLeadCallPermission failed with Meta error 132001 ("template does not exist") because nothing in this app ever created the `call_permission_request` WhatsApp template on a tenant's own WABA — it's per-WABA, so every tenant needs their own approved copy, and the original Calling build only ever referenced it by name/language (config('calling.call_permission_request_template')), assuming someone created it manually.

App\Actions\Platform\CreateCallPermissionRequestTemplate (Lead Sources page, WhatsApp row, "Create Call Permission Template" button, Gate::authorize('update', $tenant) same as every other mutation on this page — NOT mother-admin-restricted) submits `POST /{waba_id}/message_templates` using config('calling.call_permission_request_template')'s now-4-key contract (name/language/body/footer — body/footer are new, only this action uses them). Deliberately narrow — NOT general WhatsApp template management (see WhatsAppMessageTemplate's own docblock for why this app otherwise never creates WhatsApp templates via Meta's API); this is the one template Calling cannot function without.

No local catalog row (unlike CreateMessengerMessageTemplate's MessengerMessageTemplate row) — WhatsApp template approval is asynchronous (PENDING then APPROVED/REJECTED hours later, outside this request), so there is nothing accurate to store at create time, and this app has no mechanism to learn the final verdict later either. The button is always shown for a WhatsApp source, every click re-submits — re-clicking after approval just surfaces Meta's own "already exists" error (acceptable, matches the user's own confirmed "just surface Meta's error" preference over any pre-check).

Real Meta validation caught live: the FOOTER component has a hard 60-character limit — a longer one is rejected outright (code 100, error_subcode 2388040), not truncated. config('calling.call_permission_request_template')['footer'] must stay at or under 60 characters if ever changed.

New optional whatsapp_business_account_id config field added to LeadSourceType::Whatsapp (configFields()/configValidationRules(), nullable — see [[WhatsApp needs an explicit WABA-to-app subscription, not just a verified webhook]] in this same file, which added the field for subscribeWhatsAppWabaToApp()) is reused here as the WABA ID this new action posts to.
