<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadAssignment>
 */
class LeadAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'assigned_to' => TenantUser::factory(),
            'assigned_by' => TenantUser::factory(),
            'assignment_note' => fake()->optional()->sentence(),
        ];
    }
}
