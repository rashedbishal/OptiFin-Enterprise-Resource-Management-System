<?php

namespace App\Livewire\Tenant\LeadSources;

use App\Models\LeadSource;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    #[Computed]
    public function isClientAdmin(): bool
    {
        return Auth::user()->isClientAdmin();
    }

    /**
     * This tenant's roles eligible to be granted "receive unassigned
     * leads" access — same baseline as FiltersLeadsQuery::assignableUsers()
     * (lead-management module attached), not restricted to a specific
     * permission level within it. Client-Admin-only (the toggle itself is
     * gated the same way), but harmless to compute either way.
     *
     * @return Collection<int, Role>
     */
    #[Computed]
    public function rolesEligibleForUnassigned(): Collection
    {
        return Role::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->whereHas('modules', fn ($query) => $query->where('slug', 'lead-management'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Every (lead_source_id, role_id) grant for this tenant's sources, as a
     * flat array for an O(1) "is this role already granted on this source"
     * check in the Blade view — avoids an N+1 of per-row relation queries
     * across the lead sources table.
     *
     * @return array<string, bool> keyed "{leadSourceId}:{roleId}", every
     *                             value always true — existence of the key
     *                             is what the view actually checks via
     *                             isset()
     */
    #[Computed]
    public function unassignedGrants(): array
    {
        return DB::table('lead_source_unassigned_roles')
            ->join('lead_sources', 'lead_sources.id', '=', 'lead_source_unassigned_roles.lead_source_id')
            ->where('lead_sources.tenant_id', Auth::user()->tenant_id)
            ->get(['lead_source_unassigned_roles.lead_source_id', 'lead_source_unassigned_roles.role_id'])
            ->mapWithKeys(fn ($row) => ["{$row->lead_source_id}:{$row->role_id}" => true])
            ->all();
    }

    /**
     * Flips a single (lead source, role) grant on or off — the only
     * mutation this otherwise read-only tenant page has (see
     * LeadSourcePolicy::update()'s own docblock). Deliberately a single
     * toggle rather than a "select roles, Save" form, matching this
     * module's existing click-to-toggle idiom (Platform\Clients\LeadSources
     * ::toggleLeadStatus()/toggleCurrency()).
     */
    public function toggleUnassignedRole(string $leadSourceId, string $roleId): void
    {
        $source = LeadSource::where('tenant_id', Auth::user()->tenant_id)->findOrFail($leadSourceId);

        Gate::authorize('update', $source);

        $role = Role::where('tenant_id', Auth::user()->tenant_id)->findOrFail($roleId);

        if ($source->unassignedRoles()->where('roles.id', $role->id)->exists()) {
            $source->unassignedRoles()->detach($role->id);
        } else {
            $source->unassignedRoles()->attach($role->id);
        }

        unset($this->unassignedGrants);
    }
}
