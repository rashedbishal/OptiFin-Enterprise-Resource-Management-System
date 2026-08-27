---
paths:
  - 'app/{Models,Livewire,Http/Controllers/Api}/**'
---

# Api

## Lead Management module ported from OpticoreLeadManagementHub
The Lead Management module (Lead, LeadSource, LeadAssignment, LeadStatusLog, LeadNote models; LeadSourceType/LeadStatus/LeadPriority/LeadDistributionMode/AutoDistributionMethod enums; LeadAssignmentService; the webhook capture flow in app/Http/Controllers/Api/LeadWebhookController.php; the IMAP email-polling FetchEmailLeads command + ProcessEmailLeadSource job) is ported from a sibling project, `/media/rashed/HDD Drive 1/OpticoreLeadManagementHub` (Laravel+Inertia/React), adapted onto this project's Blade/Livewire stack and its Tenant/TenantUser/Role/Module/Permission foundation. Lead sources are configured by the Platform Admin under `/admin/clients/{tenant}/lead-sources` (App\Livewire\Platform\Clients\LeadSources) — the tenant side (`/lead-sources`) is read-only, matching the reference app's split. Webhook URLs are keyed by `tenant_code` (not a separate slug column). If building another ERP module with a similar "external capture + per-client config" shape, check that reference project first — it likely already solved it.
