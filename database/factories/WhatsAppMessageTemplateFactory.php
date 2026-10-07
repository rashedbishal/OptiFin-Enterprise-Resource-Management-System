<?php

namespace Database\Factories;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\Tenant;
use App\Models\WhatsAppMessageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppMessageTemplate>
 */
class WhatsAppMessageTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Created against the template's own tenant_id, same reasoning
            // as TaskFactory/LeadFactory's own defaults (see .ai/rules/factories.md).
            'lead_source_id' => fn (array $attributes): string => LeadSource::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'type' => LeadSourceType::Whatsapp,
            ])->id,
            'name' => 'order_update_'.fake()->unique()->numberBetween(1, 1_000_000),
            'language' => 'en_US',
            'variable_count' => 1,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
