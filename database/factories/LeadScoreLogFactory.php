<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadScoreLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadScoreLog>
 */
class LeadScoreLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lead_id' => Lead::factory(),
            'score' => fake()->numberBetween(0, 100),
            'raw_total' => fake()->numberBetween(0, 100),
            'breakdown' => [],
            'trigger' => 'manual',
            'computed_at' => now(),
        ];
    }
}
