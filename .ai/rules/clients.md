---
paths:
  - 'app/{Enums/LeadSourceType.php,Models/LeadSource.php,Livewire/Platform/Clients/LeadSources.php}'
  - 'app/{Enums/LeadSourceType.php,Livewire/Platform/Clients/LeadSources.php}'
---

# Clients

## Manual lead entry: LeadSourceType::Manual and its exhaustive-match traps
A tenant user with lead-management:create can manually key in a lead from `/leads` ("New Lead" — LeadPolicy::create, App\Livewire\Tenant\Leads\Index::createLead/saveLead, App\Actions\Tenant\CreateManualLead). Every Lead still needs a source_id, so each tenant gets one lazily-created LeadSource (LeadSourceType::Manual, name "Custom") the first time this runs — distinct from the Platform-Admin-configured external channels a LeadSource normally represents.

Adding this enum case required updating every exhaustive `match ($type)`/`match ($source->type)` over LeadSourceType, not just the enum's own label()/icon()/configFields()/configValidationRules(): LeadSource::webhookUrl() (returns null for Manual) and Platform\Clients\LeadSources::testConnection() (returns a stub "nothing to test" result) both throw UnhandledMatchError otherwise. LeadSources::typeOptions() explicitly filters Manual out of the Platform Admin's create-source dropdown — it must never be creatable/editable there. Adding another LeadSourceType case must repeat this audit (grep for `match (\$.*type)` over the enum) before assuming label/icon/configFields alone are enough.

Non-admin creators are auto-assigned the lead they create (LeadPolicy::view requires isClientAdmin() or assigned_to === self, so an unassigned lead they created would be invisible to them); client admins get an optional assignee dropdown instead, same as elsewhere in Leads.

## Manual lead entry: LeadSourceType::Manual and its exhaustive-match traps
A tenant user with lead-management:create can manually key in a lead from `/leads` ("New Lead" — LeadPolicy::create, App\Livewire\Tenant\Leads\Index::createLead/saveLead, App\Actions\Tenant\CreateManualLead). Every Lead still needs a source_id, so each tenant gets one lazily-created LeadSource (LeadSourceType::Manual, name "Custom") the first time this runs — distinct from the Platform-Admin-configured external channels a LeadSource normally represents.

Adding this enum case required updating every exhaustive `match ($type)`/`match ($source->type)` over LeadSourceType, not just the enum's own label()/icon()/configFields()/configValidationRules(): LeadSource::webhookUrl() (returns null for Manual) and Platform\Clients\LeadSources::testConnection() (returns a stub "nothing to test" result) both throw UnhandledMatchError otherwise. LeadSources::typeOptions() explicitly filters Manual out of the Platform Admin's create-source dropdown — it must never be creatable/editable there. Adding another LeadSourceType case must repeat this audit (grep for `match (\$.*type)` over the enum) before assuming label/icon/configFields alone are enough.

Non-admin creators are auto-assigned the lead they create (LeadPolicy::view requires isClientAdmin() or assigned_to === self, so an unassigned lead they created would be invisible to them); client admins get an optional assignee dropdown instead, same as elsewhere in Leads.

The manual-entry form also collects a fuller CRM field set beyond the Lead baseline: `company`, `website`, `industry`, `location`, `service_interest` (all nullable strings), `estimated_budget`/`deal_value` (decimal:2, tenant-currency-labeled via Auth::user()->tenant->currency), `win_probability` (0-100 int), `next_follow_up_at` (date). All are real Lead columns (not source_data JSON) so they're queryable/filterable later, all nullable since only the manual-entry path populates them today, and Show.blade.php renders them in a "CRM details" block only when at least one is present (harmless no-op for webhook-sourced leads).

## A select-type config field's key must exist in $config before it renders, or entangle() silently drops it
LeadSourceType::configFields() entries with `'type' => 'select'` render via `<x-dropdown-select>`, which binds through Alpine's `$wire.entangle('config.{key}')` — not a native wire:model. entangle() requires the target path to already exist on the component; if `config` doesn't have that key yet (e.g. still `[]`), the browser throws "Livewire property cannot be found on component" and the selection never reaches the server. Any field with `depends_on` pointing at it then stays permanently disabled, and the value silently never saves (this bit Instagram's `auth_mode` and, unnoticed, LinkedIn's `lead_type` too — direct `->set()` in tests bypasses entangle() and can't catch it).

Fix: `LeadSources::updatedType()` seeds `$config = array_fill_keys(array_column($type->configFields(), 'key'), null)` whenever `type` changes, and `edit()` merges the same defaults under the source's stored config. Adding a new `select`-type field to any LeadSourceType case is covered by this automatically — but if you ever bypass `updatedType()`/`edit()` to set `type`/`config` some other way, you must seed the same way or the pattern breaks again.

## WhatsApp needs an explicit WABA-to-app subscription, not just a verified webhook
Fixed 2026-10-14 after a real production failure: a WhatsApp lead source's webhook can pass Meta's verification handshake (GET, 200) and have `messages` ticked in Webhook Fields, and still never receive a single real event. Confirmed live: `GET /{waba-id}/subscribed_apps` returned an empty `data` array even with a fully verified, correctly-configured Callback URL — Meta requires a SEPARATE, explicit `POST /{waba-id}/subscribed_apps` call to actually link the app to the WhatsApp Business Account. This is WhatsApp Cloud API's own equivalent of what Messenger already gets via subscribeFacebookPageToApp() for Pages — but unlike Messenger, nothing in this app used to make that call for WhatsApp at all.

New optional config field `whatsapp_business_account_id` (LeadSourceType::Whatsapp's configFields()/configValidationRules(), nullable — same backward-compatible opt-in pattern as app_secret, so an existing WhatsApp source with no WABA ID still passes testConnection(), it just can't auto-subscribe yet). testWhatsappConnection() now calls the new subscribeWhatsAppWabaToApp() after the phone-number/token check succeeds, same shape as subscribeFacebookPageToApp() but with no `subscribed_fields` param (WhatsApp has no per-field subscription at this specific endpoint — only the Dashboard's Webhook fields checkboxes control that).

If diagnosing "verified webhook but zero messages arrive" again for a WhatsApp source, check this first: `GET /{waba-id}/subscribed_apps` with the stored access_token — an empty `data` array means exactly this gap, regardless of what the Meta dashboard UI shows as "saved."
