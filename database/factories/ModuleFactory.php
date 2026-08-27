<?php

namespace Database\Factories;

use App\Enums\ModuleScope;
use App\Enums\ModuleStatus;
use App\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Module>
 */
class ModuleFactory extends Factory
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
            'description' => fake()->sentence(),
            'scope' => ModuleScope::Tenant,
            'is_system' => false,
            'status' => ModuleStatus::Active,
            'sort_order' => 0,
        ];
    }

    public function platformScope(): static
    {
        return $this->state(fn () => ['scope' => ModuleScope::Platform]);
    }

    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }
}
