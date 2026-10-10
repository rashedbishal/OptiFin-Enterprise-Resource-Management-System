---
paths:
  - 'app/{Models/LeadSource.php,Models/Role.php,Policies/LeadPolicy.php,Policies/LeadSourcePolicy.php,Services/CallRouter.php,Listeners/SendNewLeadNotification.php,Livewire/Tenant/LeadSources/Index.php,Livewire/Tenant/Leads/Concerns/FiltersLeadsQuery.php,Livewire/Tenant/Users/Index.php}'
---

# Users

## "Receive unassigned leads" — role×lead-source visibility, never self-claim
LeadSource::unassignedRoles()/Role::unassignedLeadSources() (pivot `lead_source_unassigned_roles`) lets a Client Admin grant a specific role visibility+working rights on UNassigned leads from one specific LeadSource row (not LeadSourceType — per-row granularity, toggled on the tenant's otherwise-read-only Lead Sources page via LeadSourcePolicy::update(), a second deliberate mutation exception alongside delete()).

This is additive-only and deliberately never a claim/self-assign path:
- LeadPolicy::view()/update() add `|| hasUnassignedAccess()` (true only while assigned_to IS NULL and the lead's source is granted to the user's role) — but LeadPolicy::assign() is UNTOUCHED, still Client-Admin-only. Multiple qualifying users can view/work the same unassigned lead simultaneously; only a Client Admin ever sets assigned_to. Don't add a self-claim action without a fresh explicit request — the "never auto-claim" behavior was a specific, deliberate user answer, not an oversight.
- FiltersLeadsQuery::applyAssignmentScope() mirrors the same OR-condition at the list level — keep the two in sync, or a list surfaces a lead the policy then 403s on when opened.
- SendNewLeadNotification notifies qualifying active Client Users too now (not just admins), but only when the lead is still unassigned at LeadReceived time.
- CallRouter's AdminFallback tier (an unassigned lead's call) now unions admins with every active, call-capable, non-busy TenantUser whose role qualifies for that call's specific lead_source_id — routed_user_ids must stay sorted (`sort()`), concatenating two independently-sorted pools isn't globally sorted. The stored routing_mode stays `AdminFallback` even when every routed id came from the role pool, not admins.
- Users\Index::receivesUnassignedWhatsAppLeads() suppresses the per-user Backup Calls toggle (is_whatsapp_backup_receiver) for anyone whose role qualifies on any WhatsApp-type source — both server-side (toggleBackupReceiver no-ops) and in the Blade — since CallRouter already rings them for that case.

TenantUser::$role is now `@property-read Role` (non-nullable, matches role_id's NOT NULL FK) and PlatformUser::$role is `@property-read Role|null` (matches its nullable role_id) — don't re-add defensive `$user->role === null` checks on a TenantUser, Larastan will flag them as always-false.
