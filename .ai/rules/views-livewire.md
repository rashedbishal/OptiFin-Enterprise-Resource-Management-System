---
paths:
  - 'resources/views/livewire/**'
---

# Views Livewire

## Full-page Livewire component views must not wrap their own layout
Route::livewire() page components are auto-wrapped in a layout by Livewire itself: default is `layouts::app` (resources/views/layouts/app.blade.php), overridable per-component with `#[Layout('layouts.admin')]` etc. The component's own blade view must therefore be ONLY its single root element's inner content (no `<x-layouts::app>`/`<x-layouts::admin>` wrapper) or Livewire throws "Multiple root elements detected". Page `<title>` comes from `#[Title('...')]` on the class, not from a `:title` prop passed to a layout wrapper. Platform-area components use `#[Layout('layouts.admin')]`; tenant-area components rely on the default `layouts::app`; guest/auth pages (e.g. Platform login) use `#[Layout('layouts.auth')]`.

## Never use @js(...) inside a Flux component attribute — it breaks deploys
e.g. `<flux:button wire:click="foo(@js($x))">`. Livewire's morph-aware Blade compiler stretches the match while balancing parentheses and builds a regex from it; on production's PCRE (not locally!) that regex becomes too large and `view:cache` fails, so deploy.sh aborts. Pass values as plain quoted strings ('{{ $id }}' for safe ids, base64 for names) or via data-* attributes read with $el.dataset. Guarded by a test in tests/Feature/Platform/LogsPageTest.php (platform views); @js on plain HTML elements is fine.
