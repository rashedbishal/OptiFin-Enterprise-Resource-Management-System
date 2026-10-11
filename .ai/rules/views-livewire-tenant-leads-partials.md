---
paths:
  - 'app/Livewire/Tenant/Leads/Show.php,resources/views/livewire/tenant/leads/partials/status-assignment-form.blade.php'
---

# Views Livewire Tenant Leads Partials

## Detail-page delete (Unqualified) ignores isIndividuallyDeletable() — table/bulk delete doesn't
Updated 2026-10-11, confirmed live by the user (a WhatsApp lead marked Unqualified had no delete button — the detail page's delete flow was still inheriting the table/bulk-delete-only source-type restriction).

Show::confirmDelete()/delete() and status-assignment-form.blade.php's button condition do NOT check LeadSourceType::isIndividuallyDeletable() — once a lead is Unqualified, it's deletable from the detail page regardless of source (WhatsApp/Messenger/FB Lead Ads/Instagram/LinkedIn/CSV Import included), by explicit user decision.

Index::delete()/performBulkDelete() (table row delete, bulk delete) are UNCHANGED and still enforce isIndividuallyDeletable() with no Unqualified exception — only Email/WebsiteForm/Manual leads are deletable there. This asymmetry is deliberate, not a bug: the two paths now have two different protections (status-gated vs source-type-gated) for the same underlying action. See tests/Feature/Leads/MergedLeadDeletionProtectionTest.php's "the individual-deletion source-type restriction still wins over the merged-lead rule" test, which still covers the Index.php path.
