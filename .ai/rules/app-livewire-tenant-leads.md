---
paths:
  - 'resources/views/layouts/app.blade.php,resources/views/layouts/app/sidebar.blade.php,app/Livewire/Tenant/Leads/Index.php'
---

# App Livewire Tenant Leads

## One NotificationBell mount; openLeadId always mirrors selectedLeadId
NotificationBell is mounted exactly once now, in layouts/app.blade.php's content-area bar — that bar is unconditionally visible (not `hidden lg:flex`) and is the only place it renders; layouts/app/sidebar.blade.php's mobile header no longer carries its own copy. Two live instances (one desktop, one mobile, CSS-hidden rather than conditionally rendered) each ran their own unread-count/notifications queries and held their own Echo subscription for a bell only one of which was ever visible — don't re-add a second `<livewire:tenant.notification-bell />` tag anywhere; reposition the one via CSS instead if a different layout needs it elsewhere.

Index::$openLeadId (the `?lead=` query param a notification link opens) is now kept in sync with $selectedLeadId at every place the latter changes — set together in viewLead(), cleared together in the showLeadPanel-closed branch of updated(), in onPanelLeadDeleted(), and in delete(). If you add another place that nulls or sets selectedLeadId, mirror the same change onto openLeadId — otherwise the URL drifts from whichever lead's panel is actually open, and a page refresh reopens the wrong one.

## Never re-add <flux:sidebar.collapse /> on desktop/laptop
The sidebar on desktop and laptop viewports is non-collapsible. The sidebar collapse button (`<flux:sidebar.collapse />`) must NOT be present in `resources/views/layouts/app/sidebar.blade.php`, `resources/views/layouts/admin/sidebar.blade.php`, or any desktop layout. Sidebar toggling is strictly reserved for mobile and tablet viewports via `<flux:sidebar.toggle class="lg:hidden" ... />`. Do not re-add `<flux:sidebar.collapse />`.

