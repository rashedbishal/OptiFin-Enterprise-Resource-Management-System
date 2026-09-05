<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadDetailUpdateLog;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadDetailUpdateLog>
 */
class LeadDetailUpdateLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'changed_by' => TenantUser::factory(),
            'changed_fields' => fake()->randomElements(['name', 'company', 'email', 'phone'], 2),
            'action' => null,
            'action_date' => null,
        ];
    }
}
