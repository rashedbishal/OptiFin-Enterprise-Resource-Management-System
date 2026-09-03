---
paths:
  - 'resources/views/components/confirm-*.blade.php,app/Livewire/**/Index.php,app/Livewire/Tenant/Leads/{Index,Show}.php'
---

# Livewire Tenant Leads

## No native browser confirm() — use x-confirm-modal / x-confirm-password-delete-modal
Every destructive/reversing action in this app uses a Flux-modal confirmation, never Livewire's `wire:confirm` (native browser confirm dialog) — deliberately removed everywhere it used to appear (deletes across Admins/Modules/Roles/Users/LeadManagement Settings/Lead Sources, plus the pipeline-status-removal and meeting/follow-up cancel confirms).

Pattern for a plain Yes/No confirm: the trigger button calls `confirmX($id)` (not the real action) to stash a pending id/flag and flip a `showXModal` bool; `<x-confirm-modal wire:model.self="showXModal" :title="..." confirm-action="xConfirmed" />` renders the modal; `xConfirmed()` calls the real, unchanged action method (e.g. `delete($id)`) and resets state. The real action method keeps its original name/signature so existing tests calling it directly still work — only the entry point changes.

Lead deletion specifically requires the acting tenant user's own current password on top of that confirm (see `App\Livewire\Tenant\Leads\Concerns\ConfirmsLeadDeletionPassword` — shared by `Index::confirmDeleteLead`/`deleteLeadConfirmed` and `Show::confirmDelete`/`deleteConfirmed`), rendered via `<x-confirm-password-delete-modal>` instead of the plain one. This is deliberately heavier than every other delete in the app since a lead carries a client's CRM history.

Gotcha: Flux modal content is always rendered into the DOM (visibility is Alpine/CSS-toggled, not conditional Blade), so a `<x-confirm-modal>`'s button text (e.g. "Delete") is present in the page HTML even when closed — don't write `assertDontSee('Delete')`-style broad text assertions; assert on the specific `wire:click="..."` trigger instead (see FacebookLeadAdsSpreadsheetTest for the pattern).
