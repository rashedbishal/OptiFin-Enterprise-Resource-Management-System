---
paths:
  - 'app/Livewire/Tenant/Leads/Show.php,resources/views/livewire/tenant/leads/show.blade.php,resources/views/livewire/tenant/leads/partials/{contact-info-card,pipeline-attributes-card,detail-card,activity-card,note-form,status-assignment-form}.blade.php'
  - 'app/Livewire/Tenant/Leads/Show.php,resources/views/livewire/tenant/leads/{show,show-panel}.blade.php,resources/views/livewire/tenant/leads/partials/{header,lead-hero,status-assignment-form}.blade.php'
---

# Livewire Tenant Leads Partials

## Re-shimmering skeletons on tab switch needs a distinct wire:key per branch
A wire:init on an @if/@else branch's root does NOT refire on every switch back to that branch. Livewire's morph reuses the SAME DOM node across branches that occupy the same tree position — confirmed empirically by tagging the node with a JS marker and watching it survive a tab switch. Without a distinct wire:key per branch, wire:init only ever fires once (initial mount) and silently never refires.

Fix used on Show.php's Overview/Activity tabs: each branch root gets its own wire:key ("overview-tab-content" / "activity-tab-content") plus wire:init="markTabContentReady". Show::$tabContentReady resets to false in switchTab() and flips true via markTabContentReady(), driving the same $showSkeletonByDefault skeleton pattern as elsewhere — purely a visual reveal-together toggle, doesn't gate any actual query (activity() etc. stay behind $initialLoadComplete).

Partials shared with show-panel.blade.php's Kanban flyout (which has no tabs, so $tabContentReady never gets set) branch on `$panel`: $listLoadingTargets/$showSkeletonByDefault use 'completeInitialLoad'/$initialLoadComplete when $panel is true, 'switchTab,markTabContentReady'/$tabContentReady otherwise.

Also: avoid writing a literal "@if"/"@else" at the start of a line inside a PHP docblock — Pint's phpdoc_separation fixer misreads it as a PHPDoc tag and mangles the formatting. Say "a Blade if/else" instead.

## Lead detail-page delete moved to Status & Assignment card, gated on Unqualified
Deleting a lead from the detail page (Show.php) is now ONLY offered from the Status & Assignment card (status-assignment-form.blade.php, full-page-only — status-assignment-form lives on the Activity Timeline tab, not Overview), and only once the lead's status is 'unqualified' (LeadStatus slug, one of the 8 legacy-seeded statuses). The old unconditional Delete buttons in header.blade.php (Kanban flyout) and lead-hero.blade.php (full page) were removed entirely — the flyout now never shows a delete button at all, by design (confirmed with the user).

This is enforced in BOTH places, not just the button's visibility condition: `Show::confirmDelete()` and `Show::delete()` both independently check `$this->lead->status->slug !== 'unqualified'` and refuse with a toast — same "button hiding isn't enough on its own" discipline as `isIndividuallyDeletable()`'s adjacent check. Don't rely on Blade visibility alone for this gate.

Deliberately scoped to the detail page ONLY — `Index::delete()`/`performBulkDelete()` (table row delete, bulk delete) are untouched and still allow deleting a lead of any status, per an explicit user decision to keep this additive rather than a global rule. Don't extend the Unqualified requirement to Index.php without a fresh explicit request.

Every existing merge-protection/active-call/password test that drives `Show::confirmDelete()`/`delete()` directly (not through the button) now needs `'status_id' => leadStatus('unqualified')->id` on its lead fixture, or it gets refused by this new check instead of the scenario it's meant to test — see LeadCallDeletionTest/LeadDeletionAuditTest/MergedLeadDeletionProtectionTest/StaleMergedLeadShowTest/LeadDetailPanelTest for the fixed pattern.
