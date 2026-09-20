<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadConversationDeletion;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadConversationDeletion>
 */
class LeadConversationDeletionFactory extends Factory
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
            'channel' => 'messenger',
            'summary' => [],
        ];
    }
}
