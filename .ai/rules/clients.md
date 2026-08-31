---
paths:
  - 'app/{Enums/LeadSourceType.php,Models/LeadSource.php,Livewire/Platform/Clients/LeadSources.php}'
---

# Clients

## Manual lead entry: LeadSourceType::Manual and its exhaustive-match traps
A tenant user with lead-management:create can manually key in a lead from `/leads` ("New Lead" — LeadPolicy::create, App\Livewire\Tenant\Leads\Index::createLead/saveLead, App\Actions\Tenant\CreateManualLead). Every Lead still needs a source_id, so each tenant gets one lazily-created LeadSource (LeadSourceType::Manual, name "Manual Entry") the first time this runs — distinct from the Platform-Admin-configured external channels a LeadSource normally represents.

Adding this enum case required updating every exhaustive `match ($type)`/`match ($source->type)` over LeadSourceType, not just the enum's own label()/icon()/configFields()/configValidationRules(): LeadSource::webhookUrl() (returns null for Manual) and Platform\Clients\LeadSources::testConnection() (returns a stub "nothing to test" result) both throw UnhandledMatchError otherwise. LeadSources::typeOptions() explicitly filters Manual out of the Platform Admin's create-source dropdown — it must never be creatable/editable there. Adding another LeadSourceType case must repeat this audit (grep for `match (\$.*type)` over the enum) before assuming label/icon/configFields alone are enough.

Non-admin creators are auto-assigned the lead they create (LeadPolicy::view requires isClientAdmin() or assigned_to === self, so an unassigned lead they created would be invisible to them); client admins get an optional assignee dropdown instead, same as elsewhere in Leads.

## Manual lead entry: LeadSourceType::Manual and its exhaustive-match traps
A tenant user with lead-management:create can manually key in a lead from `/leads` ("New Lead" — LeadPolicy::create, App\Livewire\Tenant\Leads\Index::createLead/saveLead, App\Actions\Tenant\CreateManualLead). Every Lead still needs a source_id, so each tenant gets one lazily-created LeadSource (LeadSourceType::Manual, name "Manual Entry") the first time this runs — distinct from the Platform-Admin-configured external channels a LeadSource normally represents.

Adding this enum case required updating every exhaustive `match ($type)`/`match ($source->type)` over LeadSourceType, not just the enum's own label()/icon()/configFields()/configValidationRules(): LeadSource::webhookUrl() (returns null for Manual) and Platform\Clients\LeadSources::testConnection() (returns a stub "nothing to test" result) both throw UnhandledMatchError otherwise. LeadSources::typeOptions() explicitly filters Manual out of the Platform Admin's create-source dropdown — it must never be creatable/editable there. Adding another LeadSourceType case must repeat this audit (grep for `match (\$.*type)` over the enum) before assuming label/icon/configFields alone are enough.

Non-admin creators are auto-assigned the lead they create (LeadPolicy::view requires isClientAdmin() or assigned_to === self, so an unassigned lead they created would be invisible to them); client admins get an optional assignee dropdown instead, same as elsewhere in Leads.

The manual-entry form also collects a fuller CRM field set beyond the Lead baseline: `company`, `website`, `industry`, `location`, `service_interest` (all nullable strings), `estimated_budget`/`deal_value` (decimal:2, tenant-currency-labeled via Auth::user()->tenant->currency), `win_probability` (0-100 int), `next_follow_up_at` (date). All are real Lead columns (not source_data JSON) so they're queryable/filterable later, all nullable since only the manual-entry path populates them today, and Show.blade.php renders them in a "CRM details" block only when at least one is present (harmless no-op for webhook-sourced leads).
