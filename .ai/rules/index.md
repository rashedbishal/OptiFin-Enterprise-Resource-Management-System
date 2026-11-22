# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Actions/Platform/{CreateCallPermissionRequestTemplate,DeleteCallPermissionRequestTemplate}.php,config/calling.php | .ai/rules/actions-platform.md |
| app/{Models,Livewire,Http/Controllers/Api}/** | .ai/rules/api.md |
| app/Actions/{Platform/{CreateCallPermissionRequestTemplate,DeleteCallPermissionRequestTemplate}.php,Tenant/RequestLeadCallPermission.php} | .ai/rules/app-actions-platform.md |
| resources/views/layouts/app.blade.php,resources/views/layouts/app/sidebar.blade.php,app/Livewire/Tenant/Leads/Index.php | .ai/rules/app-livewire-tenant-leads.md |
| app/{Models/Deal.php,Models/DealStatusLog.php,Enums/DealStatus.php,Services/LeadMergeService.php} | .ai/rules/app-models.md |
| app/Services/LeadMergeService.php | .ai/rules/app-services.md |
| app/{Enums/LeadSourceType.php,Models/LeadSource.php,Livewire/Platform/Clients/LeadSources.php}, app/{Enums/LeadSourceType.php,Livewire/Platform/Clients/LeadSources.php} | .ai/rules/clients.md |
| app/Livewire/Tenant/NotificationBell.php,app/Notifications/*.php,app/Console/Commands/SendLeadAlertNotifications.php | .ai/rules/commands.md |
| config/calling.php,resources/views/livewire/platform/clients/lead-sources.blade.php | .ai/rules/config-views-livewire-platform-clients.md |
| app/Console/Commands/SendLeadAlertNotifications.php | .ai/rules/console-commands.md |
| app/Http/Controllers/Api/LeadWebhookController.php | .ai/rules/controllers-api.md |
| app/Livewire/Tenant/NotificationBell.php,resources/views/livewire/tenant/notification-bell.blade.php,resources/css/app.css | .ai/rules/css.md |
| app/Events/{LeadReceived,LeadUpdated}.php,app/Livewire/Tenant/Leads/Index.php | .ai/rules/events-livewire-tenant-leads.md |
| app/Events/{LeadReceived,LeadUpdated}.php | .ai/rules/events.md |
| app/Models/{Lead,Tenant}.php,app/Actions/Platform/CreateClient.php,database/factories/TenantFactory.php | .ai/rules/factories.md |
| app/Jobs/ProcessEmailLeadSource.php | .ai/rules/jobs.md |
| resources/{js,css}/** | .ai/rules/jscss.md |
| app/Livewire/Platform/LeadManagement/Settings.php | .ai/rules/lead-management.md |
| app/{Models/LeadTag.php,Livewire/Tenant/LeadTags/**,Policies/LeadTagPolicy.php} | .ai/rules/lead-tags.md |
| app/Livewire/Tenant/Leads/**,resources/views/livewire/tenant/{leads-kanban,leads/partials/detail-card}.blade.php | .ai/rules/leads-kanbanleads-partials.md |
| app/Services/LeadAdminNotifier.php,app/Notifications/MeetingUpdatedNotification.php,app/Notifications/FollowUpUpdatedNotification.php,app/Livewire/Tenant/Leads/Index.php,app/Livewire/Tenant/Leads/Show.php | .ai/rules/leads-livewire-tenant-leads.md |
| app/Livewire/Tenant/Leads/Index.php,app/Livewire/Tenant/NotificationBell.php | .ai/rules/leads-livewire-tenant.md |
| resources/views/livewire/tenant/leads/{show,show-panel}.blade.php,resources/views/livewire/tenant/leads/partials/{lead-hero,contact-info-card,pipeline-attributes-card,detail-card,note-form,status-assignment-form}.blade.php,resources/views/livewire/tenant/lead-sources.blade.php | .ai/rules/leads-partials-views-livewire-tenant.md |
| resources/views/livewire/tenant/leads/partials/pipeline-stepper.blade.php, resources/views/livewire/tenant/leads/partials/lead-hero.blade.php, resources/views/livewire/tenant/leads/partials/{deals-card,tasks-card,notes-card}.blade.php, resources/views/livewire/tenant/leads/partials/notes-card.blade.php, resources/views/livewire/tenant/leads/partials/calls-card.blade.php, resources/views/livewire/tenant/leads/partials/{messenger-chat-card,detail-card}.blade.php | .ai/rules/leads-partials.md |
| app/{Livewire/Tenant/Leads,Policies,Exports}/** | .ai/rules/leads-policies-exports.md |
| app/Livewire/Tenant/Leads/**, app/Livewire/Tenant/Leads/Index.php, app/Livewire/Tenant/Leads/Show.php, app/Livewire/Tenant/Leads/SourceLeads.php, app/Livewire/Tenant/Leads/{Index,Show}.php | .ai/rules/leads.md |
| app/Livewire/Tenant/Leads/{Index,SourceLeads}.php,resources/views/livewire/tenant/{leads,leads-kanban,leads/source-leads}.blade.php | .ai/rules/leadsleads-kanbanleads.md |
| resources/views/livewire/**,app/Livewire/** | .ai/rules/livewire-livewire.md |
| app/Livewire/Platform/Clients/LeadSources.php | .ai/rules/livewire-platform-clients.md |
| app/Livewire/Tenant/Leads/Show.php,resources/views/livewire/tenant/leads/show.blade.php,resources/views/livewire/tenant/leads/partials/{contact-info-card,pipeline-attributes-card,detail-card,activity-card,note-form,status-assignment-form}.blade.php, app/Livewire/Tenant/Leads/Show.php,resources/views/livewire/tenant/leads/{show,show-panel}.blade.php,resources/views/livewire/tenant/leads/partials/{header,lead-hero,status-assignment-form}.blade.php | .ai/rules/livewire-tenant-leads-partials.md |
| resources/views/components/confirm-*.blade.php,app/Livewire/**/Index.php,app/Livewire/Tenant/Leads/{Index,Show}.php | .ai/rules/livewire-tenant-leads.md |
| app/Notifications/MeetingUpdatedNotification.php,app/Livewire/Tenant/NotificationBell.php | .ai/rules/livewire-tenant.md |
| app/Livewire/** | .ai/rules/livewire.md |
| app/{Models,Http/Middleware,Policies}/** | .ai/rules/middleware-policies.md |
| database/migrations/2026_09_02_16*.php,app/Models/Currency.php,tests/** | .ai/rules/migrations-models.md |
| app/{Models/Task.php,Models/TaskStatusLog.php,Enums/TaskStatus.php,Console/Commands/SendTaskAlertNotifications.php} | .ai/rules/models-commands.md |
| app/Models/{PlatformUser,TenantUser}.php, app/Models/{LeadAssignment,LeadNote,LeadStatusLog,LeadDetailUpdateLog}.php, app/Models/Role.php | .ai/rules/models.md |
| app/Livewire/Tenant/Leads/Show.php,app/Notifications/MeetingCancelledNotification.php,app/Notifications/FollowUpCancelledNotification.php | .ai/rules/notifications.md |
| resources/views/livewire/tenant/leads/partials/message-composer-card.blade.php,app/Livewire/Tenant/Leads/Show.php | .ai/rules/partials-livewire-tenant-leads.md |
| resources/views/livewire/tenant/leads/partials/{lead-card,header}.blade.php,resources/views/livewire/tenant/leads-kanban.blade.php | .ai/rules/partials-views-livewire-tenant.md |
| resources/views/livewire/tenant/leads/show.blade.php,resources/views/livewire/tenant/leads/partials/{lead-hero,pipeline-stepper,contact-info-card,pipeline-attributes-card}.blade.php | .ai/rules/partials.md |
| app/Http/Controllers/Api/LeadWebhookController.php,app/Enums/LeadSourceType.php,app/Livewire/Platform/Clients/LeadSources.php,resources/views/livewire/platform/clients/lead-sources.blade.php | .ai/rules/platform-clients.md |
| app/{Actions/Platform/DeleteTenant.php,Policies/TenantPolicy.php,Livewire/Platform/Clients/Show.php}, app/{Actions/Platform/CreateCallPermissionRequestTemplate.php,Livewire/Platform/Clients/LeadSources.php},config/calling.php, app/{Actions/Platform/{CreateCallPermissionRequestTemplate,DeleteCallPermissionRequestTemplate}.php,Livewire/Platform/Clients/LeadSources.php} | .ai/rules/platform-platform-clients.md |
| app/{Policies/TenantUserPolicy.php,Livewire/Settings/{Profile,DeleteUserForm}.php,Actions/Platform/ManageClientAdminActivation.php,Models/TenantUser.php,Observers/TenantUserObserver.php} | .ai/rules/platform.md |
| resources/views/livewire/tenant/leads.blade.php, resources/views/livewire/tenant/leads-kanban.blade.php | .ai/rules/resources-views-livewire-tenant.md |
| app/Livewire/Tenant/NotificationBell.php,app/Http/Controllers/NotificationRedirectController.php,app/Services/NotificationUrlResolver.php | .ai/rules/services.md |
| app/Livewire/{Platform/Clients/LeadSources,Platform/LeadManagement/Settings,Tenant/LeadSources/Index,Tenant/Leads/Show}.php,resources/views/livewire/{tenant/lead-sources,platform/clients/lead-sources,platform/lead-management/settings,tenant/leads/show,tenant/leads/show-panel,tenant/leads/partials/activity-card}.blade.php | .ai/rules/show-paneltenant-leads-partials.md |
| resources/views/livewire/tenant/leads/partials/{messenger-chat-card,outbound-activity-log-card}.blade.php,resources/views/livewire/tenant/leads/show.blade.php,app/Livewire/Tenant/Leads/Show.php | .ai/rules/tenant-leads-livewire-tenant-leads.md |
| app/Models/LeadStatus.php,resources/views/livewire/tenant/leads/partials/pipeline-stepper.blade.php, app/Models/Lead.php,resources/views/livewire/tenant/leads/partials/detail-card.blade.php | .ai/rules/tenant-leads-partials.md |
| resources/views/livewire/tenant/leads/**, resources/views/livewire/tenant/leads/source-leads.blade.php, resources/views/livewire/tenant/leads/{show,show-panel}.blade.php, resources/views/livewire/tenant/leads/show.blade.php | .ai/rules/tenant-leads.md |
| resources/views/components/date-picker.blade.php,resources/views/livewire/tenant/leads.blade.php,resources/views/livewire/tenant/leads/source-leads.blade.php | .ai/rules/tenant-views-livewire-tenant-leads.md |
| app/Livewire/Tenant/NotificationBell.php,resources/views/livewire/tenant/notification-bell.blade.php | .ai/rules/tenant-views-livewire-tenant.md |
| app/Events/UserNotified.php,app/Listeners/BroadcastUserNotification.php,app/Livewire/Tenant/NotificationBell.php | .ai/rules/tenant.md |
| resources/css/themes/** | .ai/rules/themes.md |
| app/{Models/LeadSource.php,Models/Role.php,Policies/LeadPolicy.php,Policies/LeadSourcePolicy.php,Services/CallRouter.php,Listeners/SendNewLeadNotification.php,Livewire/Tenant/LeadSources/Index.php,Livewire/Tenant/Leads/Concerns/FiltersLeadsQuery.php,Livewire/Tenant/Users/Index.php} | .ai/rules/users.md |
| app/{Actions/Platform/CreateCallPermissionRequestTemplate.php,Livewire/Platform/Clients/LeadSources.php},resources/views/livewire/platform/clients/lead-sources.blade.php | .ai/rules/views-livewire-platform-clients.md |
| app/Livewire/Tenant/Leads/Show.php,resources/views/livewire/tenant/leads/partials/status-assignment-form.blade.php | .ai/rules/views-livewire-tenant-leads-partials.md |
| app/Livewire/Tenant/Leads/SourceLeads.php,resources/views/livewire/tenant/leads/source-leads.blade.php | .ai/rules/views-livewire-tenant-leads.md |
| app/Livewire/Tenant/Leads/Index.php,resources/views/livewire/tenant/leads.blade.php | .ai/rules/views-livewire-tenant.md |
| resources/views/livewire/** | .ai/rules/views-livewire.md |
| resources/views/layouts/app.blade.php,resources/views/livewire/tenant/**,resources/views/partials/settings-heading.blade.php | .ai/rules/views-partials.md |
| app/{Models/WhatsAppMessageTemplate.php,Services/{OutboundEligibilityService,WhatsAppSender}.php,Livewire/Tenant/WhatsAppTemplates/**,Livewire/Tenant/Leads/Show.php} | .ai/rules/whats-app-templates-tenant-leads.md |
