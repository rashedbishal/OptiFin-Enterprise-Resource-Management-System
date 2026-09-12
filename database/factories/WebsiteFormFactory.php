<?php

namespace Database\Factories;

use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\WebsiteForm;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebsiteForm>
 */
class WebsiteFormFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lead_source_id' => LeadSource::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'token_hash' => hash('sha256', Str::random(40)),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
