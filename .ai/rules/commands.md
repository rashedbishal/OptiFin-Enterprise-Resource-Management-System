---
paths:
  - 'app/Livewire/Tenant/NotificationBell.php,app/Notifications/*.php,app/Console/Commands/SendLeadAlertNotifications.php'
---

# Commands

## Notification bell: global layout component, daily-recurring alert sweep
The bell (App\Livewire\Tenant\NotificationBell) is embedded globally, not per-page: once in resources/views/layouts/app.blade.php (a new desktop-only bar above `{{ $slot }}`, since every tenant page's own breadcrumb is the first thing inside that slot) and once in layouts/app/sidebar.blade.php's mobile header. It surfaces Laravel's standard "database" notification channel — Auth::user()->notifications() — nothing bell-specific to wire up per notification type beyond adding it to NotificationBell::present()'s match. This only touches layouts/app.blade.php + sidebar.blade.php (tenant), never layouts/admin.blade.php (Platform side has no bell).

Four notification types feed it: NewLeadNotification (Client Admins only, unchanged, pre-existing), LeadAssignedNotification (the assignee, unchanged, pre-existing), and two new ones — MeetingAlertNotification/FollowUpAlertNotification — created by the daily `leads:send-alert-notifications` command (App\Console\Commands\SendLeadAlertNotifications, scheduled dailyAt 08:00). That command is the bell's counterpart to Lead::meetingAlertActive()/followUpAlertActive()'s Kanban shimmer, which is a live recomputed state with nothing persisted — deliberately fires once per lead per recipient per day for as long as the shimmer stays active (not just once when it first opens), guarded by alreadyNotifiedToday() checking `data->lead_id` + `whereDate('created_at', today())` so a same-day rerun doesn't duplicate. Recipients = every active Client Admin in the tenant + the lead's own assignee if any — mirrors LeadPolicy::view's admin-sees-all / rep-sees-own-assigned split, not re-derived from a query at read time in the bell itself (a Client User only ever sees alerts for their own leads purely because the sweep never wrote one against their notifiable for anyone else's).
