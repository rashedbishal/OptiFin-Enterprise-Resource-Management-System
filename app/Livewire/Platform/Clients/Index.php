<?php

namespace App\Livewire\Platform\Clients;

use App\Actions\Platform\CreateClient;
use App\Models\Tenant;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Clients')]
#[Layout('layouts.admin')]
class Index extends Component
{
    public bool $showModal = false;

    public string $name = '';

    public string $email = '';

    public string $country = '';

    public string $admin_name = '';

    public string $admin_email = '';

    public string $admin_password = '';

    public function mount(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('viewAny', Tenant::class);
    }

    #[Computed]
    public function clients()
    {
        return Tenant::query()->withCount('tenantUsers')->latest()->paginate(10);
    }

    public function create(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('create', Tenant::class);

        $this->reset('name', 'email', 'country', 'admin_name', 'admin_email', 'admin_password');
        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::forUser(Auth::guard('platform')->user())->authorize('create', Tenant::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255', 'unique:tenant_users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);

        app(CreateClient::class)->handle(
            tenantData: [
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'country' => $validated['country'] ?? null,
            ],
            adminData: [
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => $validated['admin_password'],
            ],
            createdBy: Auth::guard('platform')->user(),
        );

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('Client created.'));
    }
}
