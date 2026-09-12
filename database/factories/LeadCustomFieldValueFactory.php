<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadCustomFieldDefinition;
use App\Models\LeadCustomFieldValue;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadCustomFieldValue>
 */
class LeadCustomFieldValueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lead_id' => Lead::factory(),
            'lead_custom_field_definition_id' => LeadCustomFieldDefinition::factory(),
            'value' => fake()->word(),
        ];
    }
}
