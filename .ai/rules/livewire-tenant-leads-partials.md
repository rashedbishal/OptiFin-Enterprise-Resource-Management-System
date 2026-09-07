---
paths:
  - 'app/Livewire/Tenant/Leads/Show.php,resources/views/livewire/tenant/leads/show.blade.php,resources/views/livewire/tenant/leads/partials/{contact-info-card,pipeline-attributes-card,detail-card,activity-card,note-form,status-assignment-form}.blade.php'
---

# Livewire Tenant Leads Partials

## Re-shimmering skeletons on tab switch needs a distinct wire:key per branch
A wire:init on an @if/@else branch's root does NOT refire on every switch back to that branch. Livewire's morph reuses the SAME DOM node across branches that occupy the same tree position — confirmed empirically by tagging the node with a JS marker and watching it survive a tab switch. Without a distinct wire:key per branch, wire:init only ever fires once (initial mount) and silently never refires.

Fix used on Show.php's Overview/Activity tabs: each branch root gets its own wire:key ("overview-tab-content" / "activity-tab-content") plus wire:init="markTabContentReady". Show::$tabContentReady resets to false in switchTab() and flips true via markTabContentReady(), driving the same $showSkeletonByDefault skeleton pattern as elsewhere — purely a visual reveal-together toggle, doesn't gate any actual query (activity() etc. stay behind $initialLoadComplete).

Partials shared with show-panel.blade.php's Kanban flyout (which has no tabs, so $tabContentReady never gets set) branch on `$panel`: $listLoadingTargets/$showSkeletonByDefault use 'completeInitialLoad'/$initialLoadComplete when $panel is true, 'switchTab,markTabContentReady'/$tabContentReady otherwise.

Also: avoid writing a literal "@if"/"@else" at the start of a line inside a PHP docblock — Pint's phpdoc_separation fixer misreads it as a PHPDoc tag and mangles the formatting. Say "a Blade if/else" instead.
