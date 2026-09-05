---
paths:
  - 'app/Livewire/Tenant/NotificationBell.php,app/Http/Controllers/NotificationRedirectController.php,app/Services/NotificationUrlResolver.php'
---

# Services

## Notification click-through goes through NotificationRedirectController, not wire:click + wire:navigate
A notification row's link used to be `<a href="{lead url}" wire:navigate wire:click.renderless="markAsRead(...)">` — two independent requests racing each other. wire:navigate can swap the page (tearing down the NotificationBell Livewire component) before the separate markAsRead() commit is guaranteed to have reached the server, so the notification could still show unread after the user navigated to the lead and back. Fixed by routing every clickable notification through `NotificationRedirectController` (`GET /notifications/{id}/open`, name `tenant-notifications.open`): it marks the notification read and redirects to the lead URL in one atomic request. `NotificationBell::present()` now sets `url` to `route('tenant-notifications.open', $notification->id)` (or null if the lead's gone) instead of the lead URL directly; the actual lead-URL logic (Client Admin → tenant-leads.show, Client User → tenant-leads.index?lead=) lives in `App\Services\NotificationUrlResolver::resolve()`, shared by both the controller and NotificationBell. Don't reintroduce a `wire:click.renderless="markAsRead(...)"` alongside `wire:navigate` on the same anchor — that's the exact race this fixes. The small unread-dot button (no navigation involved) still calls `markAsRead()` directly via a normal (non-renderless) `wire:click` and is unaffected.
