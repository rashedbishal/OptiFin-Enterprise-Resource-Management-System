<?php

namespace App\Services;

use App\Enums\AutoDistributionMethod;
use App\Enums\LeadDistributionMode;
use App\Enums\LeadStatus;
use App\Enums\TenantUserType;
use App\Events\LeadAssigned;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Builder;

class LeadAssignmentService
{
    /**
     * Assign a lead to a tenant user, recording the assignment history.
     */
    public function assign(Lead $lead, TenantUser $assignee, ?TenantUser $assignedBy, ?string $note = null): void
    {
        $lead->update([
            'assigned_to' => $assignee->id,
            'assigned_at' => now(),
        ]);

        LeadAssignment::create([
            'lead_id' => $lead->id,
            'assigned_to' => $assignee->id,
            'assigned_by' => $assignedBy?->id,
            'assignment_note' => $note,
        ]);

        $assignee->update(['last_assigned_at' => now()]);

        event(new LeadAssigned($lead, $assignee, $assignedBy));
    }

    /**
     * Automatically assign a newly received lead based on its tenant's lead
     * distribution settings. Does nothing if distribution is manual or
     * there are no eligible users to assign to.
     */
    public function autoAssign(Lead $lead): void
    {
        $tenant = $lead->tenant;

        if (! $tenant instanceof Tenant || $tenant->lead_distribution_mode !== LeadDistributionMode::Automatic) {
            return;
        }

        $assignee = match ($tenant->auto_distribution_method) {
            AutoDistributionMethod::LeastLoaded => $this->pickLeastLoadedUser($tenant),
            AutoDistributionMethod::Random => $this->pickRandomUser($tenant),
            default => $this->pickRoundRobinUser($tenant),
        };

        if (! $assignee instanceof TenantUser) {
            return;
        }

        $this->assign($lead, $assignee, null, 'Automatically assigned.');
    }

    private function pickRoundRobinUser(Tenant $tenant): ?TenantUser
    {
        return $this->eligibleUsers($tenant)
            ->orderByRaw('last_assigned_at IS NOT NULL')
            ->orderBy('last_assigned_at')
            ->first();
    }

    private function pickLeastLoadedUser(Tenant $tenant): ?TenantUser
    {
        return $this->eligibleUsers($tenant)
            ->withCount(['assignedLeads' => function (Builder $query): void {
                $query->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost]);
            }])
            ->orderBy('assigned_leads_count')
            ->orderByRaw('last_assigned_at IS NOT NULL')
            ->orderBy('last_assigned_at')
            ->first();
    }

    private function pickRandomUser(Tenant $tenant): ?TenantUser
    {
        return $this->eligibleUsers($tenant)->inRandomOrder()->first();
    }

    /**
     * @return Builder<TenantUser>
     */
    private function eligibleUsers(Tenant $tenant): Builder
    {
        return TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_type', TenantUserType::ClientUser)
            ->where('is_active', true)
            ->whereHas('role', function (Builder $query): void {
                $query->whereHas('modules', fn (Builder $q) => $q->where('slug', 'lead-management'))
                    ->whereHas('modulePermissions', function (Builder $q): void {
                        $q->whereHas('module', fn (Builder $q) => $q->where('slug', 'lead-management'))
                            ->whereHas('permission', fn (Builder $q) => $q->where('slug', 'view'));
                    });
            });
    }
}
