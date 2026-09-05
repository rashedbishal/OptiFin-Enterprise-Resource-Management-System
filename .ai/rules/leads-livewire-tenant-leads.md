---
paths:
  - 'app/Services/LeadAdminNotifier.php,app/Notifications/MeetingUpdatedNotification.php,app/Notifications/FollowUpUpdatedNotification.php,app/Livewire/Tenant/Leads/Index.php,app/Livewire/Tenant/Leads/Show.php'
---

# Leads Livewire Tenant Leads

## Meeting/follow-up create+edit notifications, and the shared LeadAdminNotifier
App\Services\LeadAdminNotifier::notifyExcept(Lead, TenantUser $actor, Notification) replaces what used to be a private Show::notifyOtherAdmins() — extracted once Index::saveLead() needed the identical "every other active Client Admin, never the actor" rule for a second call site. Show's cancelMeeting()/cancelFollowUp()/updateDeal() and Index's saveLead() all take it as a method parameter (Livewire auto-injects it, same pattern as `assign(LeadAssignmentService $assignmentService)`) — a private per-class helper would have needed re-deriving this rule a third time.

MeetingUpdatedNotification/FollowUpUpdatedNotification cover both creating a lead with a meeting/follow-up already set (Index::saveLead(), always `rescheduled: false` — there's no "before" for a brand new lead) and editing an existing lead's date via Show::updateDeal() (`rescheduled` = whether the column already had a different non-null value). Show::updateDeal() captures the OLD date string *before* calling applyDetailChanges() but only sends the notification *after* it, reading the NEW value straight off `$this->lead` — reversing that order would either notify with a stale value or misjudge scheduled-vs-rescheduled. Clearing a date to null is never a "create/edit" — that's MeetingCancelledNotification/FollowUpCancelledNotification's job (see [[Cancellation notifications go to admins, never the actor]]), fired from the dedicated Cancel button/action only, not from updateDeal()'s generic form even though it can also null the field.
