<?php

namespace App\Livewire\Tenant;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    #[Computed]
    public function tenant()
    {
        return Auth::user()->tenant;
    }

    #[Computed]
    public function assignedModules()
    {
        return $this->tenant->activeModules;
    }

    #[Computed]
    public function totalUsers(): int
    {
        return $this->tenant->tenantUsers()->count();
    }

    #[Computed]
    public function totalRoles(): int
    {
        return $this->tenant->roles()->count();
    }
}
