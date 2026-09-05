---
paths:
  - 'app/Livewire/Tenant/Leads/Index.php,app/Livewire/Tenant/NotificationBell.php'
---

# Leads Livewire Tenant

## Notification links respect the Kanban-vs-full-page split by role
A Client Admin's "view this lead" surface is the standalone tenant-leads.show page; a Client User has no full-page view of their own at all — leads.blade.php (admin table) is the only view with a flyout modal, so a Client User only ever sees a lead's details through the Kanban board's flyout. NotificationBell::urlFor() branches on Auth::user()->isClientAdmin(): admins get route('tenant-leads.show', $leadId) unchanged; everyone else gets route('tenant-leads.index', ['lead' => $leadId]). Index now has a #[Url(as: 'lead')] public ?string $openLeadId, and mount() calls $this->viewLead($this->openLeadId) when it's set — reuses viewLead()'s own Gate::denies('view', $lead) check unchanged, so an unauthorized ?lead= is denied exactly the same way clicking a card for a lead you can't see already is. Don't send a Client User to tenant-leads.show for any future notification type — always route through tenant-leads.index?lead=.
