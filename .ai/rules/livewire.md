---
paths:
  - 'app/Livewire/**'
---

# Livewire

## Livewire class-based "Index" components: view path drops "index"
A class-based full-page component named `Index` inside a subfolder (e.g. `App\Livewire\Tenant\Users\Index`) auto-resolves to view `resources/views/livewire/tenant/users.blade.php` (NOT `tenant/users/index.blade.php`). Livewire's Finder strips a trailing `.index` segment from the generated view name. Non-"Index" classes (e.g. `Show`, `Dashboard`, `Login`) keep their full path as expected. Same applies to `App\Livewire\Platform\{Admins,Roles,Clients,Modules}\Index` in this project.
