<?php

namespace App\Livewire\Platform;

use App\Enums\PlatformUserType;
use App\Enums\TenantStatus;
use App\Models\Module;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Platform Dashboard')]
#[Layout('layouts.admin')]
class Dashboard extends Component
{
    #[Computed]
    public function totalClients(): int
    {
        return Tenant::query()->count();
    }

    #[Computed]
    public function activeClients(): int
    {
        return Tenant::query()->where('status', TenantStatus::Active)->count();
    }

    #[Computed]
    public function totalPlatformAdmins(): int
    {
        return PlatformUser::query()->where('user_type', PlatformUserType::PlatformAdmin)->count();
    }

    #[Computed]
    public function availableModules(): int
    {
        return Module::query()->count();
    }

    #[Computed]
    public function recentClients()
    {
        return Tenant::query()->latest()->take(5)->get();
    }

    #[Computed]
    public function recentAdmins()
    {
        return PlatformUser::query()
            ->where('user_type', PlatformUserType::PlatformAdmin)
            ->latest()
            ->take(5)
            ->get();
    }
}
