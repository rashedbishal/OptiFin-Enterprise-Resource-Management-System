<?php

namespace Database\Factories;

use App\Enums\LeadCustomFieldType;
use App\Models\LeadCustomFieldDefinition;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeadCustomFieldDefinition>
 */
class LeadCustomFieldDefinitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'key' => Str::slug($name),
            'type' => LeadCustomFieldType::Text,
            'options' => null,
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function number(): static
    {
        return $this->state(fn (array $attributes): array => ['type' => LeadCustomFieldType::Number]);
    }

    public function date(): static
    {
        return $this->state(fn (array $attributes): array => ['type' => LeadCustomFieldType::Date]);
    }

    /**
     * @param  array<int, string>  $options
     */
    public function select(array $options = ['Option A', 'Option B']): static
    {
        return $this->state(fn (array $attributes): array => ['type' => LeadCustomFieldType::Select, 'options' => $options]);
    }

    public function required(): static
    {
        return $this->state(fn (array $attributes): array => ['is_required' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
