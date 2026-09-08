<?php

namespace Database\Factories;

use App\Enums\LeadScoringField;
use App\Enums\LeadScoringOperator;
use App\Models\LeadScoringRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadScoringRule>
 */
class LeadScoringRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'field' => LeadScoringField::Priority->value,
            'operator' => LeadScoringOperator::Equals->value,
            'value' => 'urgent',
            'points' => fake()->numberBetween(5, 25),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
