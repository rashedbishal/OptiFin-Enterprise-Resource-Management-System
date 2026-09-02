<?php

namespace Database\Factories;

use App\Models\LeadStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeadStatus>
 */
class LeadStatusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'color' => fake()->randomElement(LeadStatus::availableColors()),
            'is_closed' => false,
            'sort_order' => 0,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['is_closed' => true]);
    }
}
