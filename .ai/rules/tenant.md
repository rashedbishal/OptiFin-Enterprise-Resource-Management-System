---
paths:
  - 'app/Events/UserNotified.php,app/Listeners/BroadcastUserNotification.php,app/Livewire/Tenant/NotificationBell.php'
---

# Tenant

## Notification bell updates via Reverb, not polling
Supersedes the earlier version of this note: the bell does NOT poll. It listens on its own private channel (`echo-private:tenant-user.{id},.notification.created`, getListeners() → refresh()) for App\Events\UserNotified, which broadcasts the instant any "database"-channel notification lands for a TenantUser. That event is dispatched by App\Listeners\BroadcastUserNotification, an auto-discovered listener on Laravel's own internal Illuminate\Notifications\Events\NotificationSent — not from the three places that call ->notify() (SendNewLeadNotification, SendLeadAssignedNotification, SendLeadAlertNotifications), so a future notification type sent via ->notify() gets real-time delivery for free with nothing to remember at the call site. The broadcast carries no payload; refresh() just re-queries the DB (unset() on the two #[Computed] properties), so there's nothing to keep in sync between the broadcast shape and NotificationBell::present()'s own formatting. Channel authorization lives in routes/channels.php: `tenant-user.{tenantUserId}` — a user may only subscribe to their own.
