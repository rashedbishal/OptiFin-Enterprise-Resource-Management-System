<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadDeletionAudit;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadDeletionAudit>
 */
class LeadDeletionAuditFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lead_id' => Lead::factory(),
            'lead_identifier' => fake()->bothify('TENANT-LEAD-###'),
            'lead_name' => fake()->name(),
            'lead_email' => fake()->safeEmail(),
            'lead_phone' => fake()->e164PhoneNumber(),
            'source_type' => 'manual',
            'is_bulk' => false,
            'cascade_summary' => [],
        ];
    }
}
