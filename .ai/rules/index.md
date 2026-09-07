# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/{Models,Livewire,Http/Controllers/Api}/** | .ai/rules/api.md |
| resources/views/layouts/app.blade.php,resources/views/layouts/app/sidebar.blade.php,app/Livewire/Tenant/Leads/Index.php | .ai/rules/app-livewire-tenant-leads.md |
| app/{Enums/LeadSourceType.php,Models/LeadSource.php,Livewire/Platform/Clients/LeadSources.php}, app/{Enums/LeadSourceType.php,Livewire/Platform/Clients/LeadSources.php} | .ai/rules/clients.md |
| app/Livewire/Tenant/NotificationBell.php,app/Notifications/*.php,app/Console/Commands/SendLeadAlertNotifications.php | .ai/rules/commands.md |
| app/Console/Commands/SendLeadAlertNotifications.php | .ai/rules/console-commands.md |
| app/Http/Controllers/Api/LeadWebhookController.php | .ai/rules/controllers-api.md |
| app/Livewire/Tenant/NotificationBell.php,resources/views/livewire/tenant/notification-bell.blade.php,resources/css/app.css | .ai/rules/css.md |
| app/Models/{Lead,Tenant}.php,app/Actions/Platform/CreateClient.php,database/factories/TenantFactory.php | .ai/rules/factories.md |
| app/Jobs/ProcessEmailLeadSource.php | .ai/rules/jobs.md |
| resources/{js,css}/** | .ai/rules/jscss.md |
| app/Livewire/Platform/LeadManagement/Settings.php | .ai/rules/lead-management.md |
| app/Livewire/Tenant/Leads/**,resources/views/livewire/tenant/{leads-kanban,leads/partials/detail-card}.blade.php | .ai/rules/leads-kanbanleads-partials.md |
| app/Services/LeadAdminNotifier.php,app/Notifications/MeetingUpdatedNotification.php,app/Notifications/FollowUpUpdatedNotification.php,app/Livewire/Tenant/Leads/Index.php,app/Livewire/Tenant/Leads/Show.php | .ai/rules/leads-livewire-tenant-leads.md |
| app/Livewire/Tenant/Leads/Index.php,app/Livewire/Tenant/NotificationBell.php | .ai/rules/leads-livewire-tenant.md |
| resources/views/livewire/tenant/leads/{show,show-panel}.blade.php,resources/views/livewire/tenant/leads/partials/{lead-hero,contact-info-card,pipeline-attributes-card,detail-card,note-form,status-assignment-form}.blade.php,resources/views/livewire/tenant/lead-sources.blade.php | .ai/rules/leads-partials-views-livewire-tenant.md |
| resources/views/livewire/tenant/leads/partials/pipeline-stepper.blade.php, resources/views/livewire/tenant/leads/partials/lead-hero.blade.php | .ai/rules/leads-partials.md |
| app/{Livewire/Tenant/Leads,Policies,Exports}/** | .ai/rules/leads-policies-exports.md |
| app/Livewire/Tenant/Leads/**, app/Livewire/Tenant/Leads/Index.php, app/Livewire/Tenant/Leads/Show.php, app/Livewire/Tenant/Leads/SourceLeads.php | .ai/rules/leads.md |
| app/Livewire/Tenant/Leads/{Index,SourceLeads}.php,resources/views/livewire/tenant/{leads,leads-kanban,leads/source-leads}.blade.php | .ai/rules/leadsleads-kanbanleads.md |
| resources/views/livewire/**,app/Livewire/** | .ai/rules/livewire-livewire.md |
| resources/views/components/confirm-*.blade.php,app/Livewire/**/Index.php,app/Livewire/Tenant/Leads/{Index,Show}.php | .ai/rules/livewire-tenant-leads.md |
| app/Notifications/MeetingUpdatedNotification.php,app/Livewire/Tenant/NotificationBell.php | .ai/rules/livewire-tenant.md |
| app/Livewire/** | .ai/rules/livewire.md |
| app/{Models,Http/Middleware,Policies}/** | .ai/rules/middleware-policies.md |
| database/migrations/2026_09_02_16*.php,app/Models/Currency.php,tests/** | .ai/rules/migrations-models.md |
| app/Models/{PlatformUser,TenantUser}.php, app/Models/{LeadAssignment,LeadNote,LeadStatusLog,LeadDetailUpdateLog}.php | .ai/rules/models.md |
| app/Livewire/Tenant/Leads/Show.php,app/Notifications/MeetingCancelledNotification.php,app/Notifications/FollowUpCancelledNotification.php | .ai/rules/notifications.md |
| resources/views/livewire/tenant/leads/partials/{lead-card,header}.blade.php,resources/views/livewire/tenant/leads-kanban.blade.php | .ai/rules/partials-views-livewire-tenant.md |
| resources/views/livewire/tenant/leads/show.blade.php,resources/views/livewire/tenant/leads/partials/{lead-hero,pipeline-stepper,contact-info-card,pipeline-attributes-card}.blade.php | .ai/rules/partials.md |
| resources/views/livewire/tenant/leads.blade.php | .ai/rules/resources-views-livewire-tenant.md |
| app/Livewire/Tenant/NotificationBell.php,app/Http/Controllers/NotificationRedirectController.php,app/Services/NotificationUrlResolver.php | .ai/rules/services.md |
| app/Livewire/{Platform/Clients/LeadSources,Platform/LeadManagement/Settings,Tenant/LeadSources/Index,Tenant/Leads/Show}.php,resources/views/livewire/{tenant/lead-sources,platform/clients/lead-sources,platform/lead-management/settings,tenant/leads/show,tenant/leads/show-panel,tenant/leads/partials/activity-card}.blade.php | .ai/rules/show-paneltenant-leads-partials.md |
| app/Models/LeadStatus.php,resources/views/livewire/tenant/leads/partials/pipeline-stepper.blade.php | .ai/rules/tenant-leads-partials.md |
| resources/views/livewire/tenant/leads/**, resources/views/livewire/tenant/leads/source-leads.blade.php | .ai/rules/tenant-leads.md |
| resources/views/components/date-picker.blade.php,resources/views/livewire/tenant/leads.blade.php,resources/views/livewire/tenant/leads/source-leads.blade.php | .ai/rules/tenant-views-livewire-tenant-leads.md |
| app/Livewire/Tenant/NotificationBell.php,resources/views/livewire/tenant/notification-bell.blade.php | .ai/rules/tenant-views-livewire-tenant.md |
| app/Events/UserNotified.php,app/Listeners/BroadcastUserNotification.php,app/Livewire/Tenant/NotificationBell.php | .ai/rules/tenant.md |
| app/Livewire/Tenant/Leads/SourceLeads.php,resources/views/livewire/tenant/leads/source-leads.blade.php | .ai/rules/views-livewire-tenant-leads.md |
| app/Livewire/Tenant/Leads/Index.php,resources/views/livewire/tenant/leads.blade.php | .ai/rules/views-livewire-tenant.md |
| resources/views/livewire/** | .ai/rules/views-livewire.md |
| resources/views/layouts/app.blade.php,resources/views/livewire/tenant/**,resources/views/partials/settings-heading.blade.php | .ai/rules/views-partials.md |
