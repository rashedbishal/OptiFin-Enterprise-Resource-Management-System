<?php

namespace App\Actions\Tenant;

use App\Enums\LeadSourceType;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\LeadAssignmentService;
use Illuminate\Support\Facades\DB;

/**
 * Keying in a lead by hand (a phone call, a walk-in, a referral) still needs
 * a LeadSource row to satisfy Lead::source_id, even though nothing was
 * actually captured through an external channel. Every tenant gets a
 * single, lazily-created LeadSourceType::Manual source for this, kept
 * separate from the Platform-Admin-configured channels a Lead Source
 * normally represents.
 */
class CreateManualLead
{
    public function __construct(private readonly LeadAssignmentService $assignmentService) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Tenant $tenant, array $data, TenantUser $createdBy, ?TenantUser $assignee): Lead
    {
        return DB::transaction(function () use ($tenant, $data, $createdBy, $assignee) {
            $source = LeadSource::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'type' => LeadSourceType::Manual],
                ['name' => 'Custom', 'is_active' => true],
            );

            $lead = Lead::create([
                'tenant_id' => $tenant->id,
                'source_id' => $source->id,
                'name' => $data['name'],
                'company' => $data['company'],
                'website' => $data['website'],
                'industry' => $data['industry'],
                'location' => $data['location'],
                'service_interest' => $data['service_interest'],
                'lead_source' => $data['lead_source'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'],
                'message' => $data['message'],
                'status_id' => $data['status_id'],
                'priority' => $data['priority'],
                'estimated_budget' => $data['estimated_budget'],
                'estimated_budget_currency_id' => $data['estimated_budget_currency_id'] ?? null,
                'deal_value' => $data['deal_value'],
                'deal_value_currency_id' => $data['deal_value_currency_id'] ?? null,
                'win_probability' => $data['win_probability'],
                'next_follow_up_at' => $data['next_follow_up_at'],
                'meeting_date' => $data['meeting_date'] ?? null,
                'meeting_time' => $data['meeting_time'] ?? null,
            ]);

            if ($assignee) {
                $this->assignmentService->assign($lead, $assignee, $createdBy);
            }

            return $lead->fresh();
        });
    }
}
