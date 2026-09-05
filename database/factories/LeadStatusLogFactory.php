<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadStatusLog;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadStatusLog>
 */
class LeadStatusLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'changed_by' => TenantUser::factory(),
            'old_status_id' => LeadStatus::factory(),
            'new_status_id' => LeadStatus::factory(),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
