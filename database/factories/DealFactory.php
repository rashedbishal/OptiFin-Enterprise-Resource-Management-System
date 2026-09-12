<?php

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Created against the deal's own tenant_id (already resolved by
            // this point in attribute expansion), same reasoning as
            // LeadFactory's source_id default — avoids spawning an
            // unrelated orphan tenant that collides with CreateClient's
            // DB-count-based tenant_code numbering (see .ai/rules/
            // factories.md).
            'lead_id' => fn (array $attributes) => Lead::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->id,
            'name' => fake()->words(2, true),
            'value' => fake()->randomFloat(2, 1000, 50000),
            'probability' => fake()->numberBetween(10, 90),
            'status' => DealStatus::Open,
            'expected_close_date' => now()->addDays(30)->toDateString(),
        ];
    }

    public function won(): static
    {
        return $this->state(fn (): array => ['status' => DealStatus::Won, 'actual_close_date' => now()->toDateString()]);
    }

    public function lost(): static
    {
        return $this->state(fn (): array => ['status' => DealStatus::Lost, 'actual_close_date' => now()->toDateString()]);
    }
}
