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
    /**
     * Starts false on every fresh mount so the very first paint can skip
     * the actual query and show a skeleton instead — same technique as
     * App\Livewire\Tenant\Leads\Index::$initialLoadComplete, scaled down:
     * this page has no filters at all, so there's no $listLoadingTargets
     * beyond completeInitialLoad() itself, fired via wire:init on the
     * view's root element.
     */
    public bool $initialLoadComplete = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', LeadSource::class);
    }

    public function completeInitialLoad(): void
    {
        $this->initialLoadComplete = true;
    }

    #[Computed]
    public function leadSources()
    {
        if (! $this->initialLoadComplete) {
            return LeadSource::query()->whereRaw('1 = 0')->get();
        }

        return LeadSource::query()->where('tenant_id', Auth::user()->tenant_id)->latest()->get();
    }
}
