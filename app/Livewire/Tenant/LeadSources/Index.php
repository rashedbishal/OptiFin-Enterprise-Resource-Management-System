<?php

namespace App\Livewire\Tenant\LeadSources;

use App\Models\LeadSource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Lead Sources')]
class Index extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', LeadSource::class);
    }

    #[Computed]
    public function leadSources()
    {
        return LeadSource::query()->where('tenant_id', Auth::user()->tenant_id)->latest()->get();
    }
}
