---
paths:
  - 'resources/views/livewire/**'
---

# Views Livewire

## Full-page Livewire component views must not wrap their own layout
Route::livewire() page components are auto-wrapped in a layout by Livewire itself: default is `layouts::app` (resources/views/layouts/app.blade.php), overridable per-component with `#[Layout('layouts.admin')]` etc. The component's own blade view must therefore be ONLY its single root element's inner content (no `<x-layouts::app>`/`<x-layouts::admin>` wrapper) or Livewire throws "Multiple root elements detected". Page `<title>` comes from `#[Title('...')]` on the class, not from a `:title` prop passed to a layout wrapper. Platform-area components use `#[Layout('layouts.admin')]`; tenant-area components rely on the default `layouts::app`; guest/auth pages (e.g. Platform login) use `#[Layout('layouts.auth')]`.
