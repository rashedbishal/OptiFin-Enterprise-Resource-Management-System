<?php

namespace Database\Factories;

use App\Enums\LeadDuplicateLinkStatus;
use App\Models\Lead;
use App\Models\LeadDuplicateLink;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadDuplicateLink>
 */
class LeadDuplicateLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lead_id' => Lead::factory(),
            'duplicate_lead_id' => Lead::factory(),
            'status' => LeadDuplicateLinkStatus::Pending,
            'matched_fields' => ['email'],
            'detected_at' => now(),
        ];
    }
}
