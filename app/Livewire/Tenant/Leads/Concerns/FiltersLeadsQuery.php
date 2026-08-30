<?php

namespace App\Livewire\Tenant\Leads\Concerns;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Shared between the main Leads list and the per-source Facebook Lead Ads
 * spreadsheet view: the status/priority/assigned-to dropdown filters, the
 * mandatory assignment scope for non-admin agents (who may only ever see
 * their own assigned leads), and the assignable-users list those dropdowns
 * are built from.
 */
trait FiltersLeadsQuery
{
    public string $status = '';

    public string $priority = '';

    public string $assignedTo = '';

    #[Computed]
    public function isClientAdmin(): bool
    {
        return Auth::user()->isClientAdmin();
    }

    #[Computed]
    public function assignableUsers()
    {
        if (! $this->isClientAdmin) {
            return collect();
        }

        return TenantUser::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->where('is_active', true)
            ->whereHas('role', fn (Builder $q) => $q->whereHas('modules', fn (Builder $m) => $m->where('slug', 'lead-management')))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return array<int, string>
     */
    public function statusOptions(): array
    {
        return LeadStatus::values();
    }

    /**
     * @return array<int, string>
     */
    public function priorityOptions(): array
    {
        return LeadPriority::values();
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function applyAssignmentScope(Builder $query): Builder
    {
        if (! $this->isClientAdmin) {
            $query->assignedTo(Auth::user()->id);
        }

        return $query;
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function applyDropdownFilters(Builder $query): Builder
    {
        if ($this->status !== '') {
            $query->byStatus(LeadStatus::from($this->status));
        }

        if ($this->priority !== '') {
            $query->byPriority(LeadPriority::from($this->priority));
        }

        if ($this->isClientAdmin && $this->assignedTo !== '') {
            if ($this->assignedTo === 'unassigned') {
                $query->unassigned();
            } else {
                $query->assignedTo($this->assignedTo);
            }
        }

        return $query;
    }
}
