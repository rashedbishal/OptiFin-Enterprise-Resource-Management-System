<?php

namespace Database\Factories;

use App\Enums\LeadSourceType;
use App\Models\LeadSource;
use App\Models\MessengerMessageTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessengerMessageTemplate>
 */
class MessengerMessageTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Created against the template's own tenant_id, same reasoning
            // as WhatsAppMessageTemplateFactory's own default (see
            // .ai/rules/factories.md).
            'lead_source_id' => fn (array $attributes): string => LeadSource::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'type' => LeadSourceType::FacebookMessenger,
            ])->id,
            'name' => 'order_update_'.fake()->unique()->numberBetween(1, 1_000_000),
            'language' => 'en',
            'variable_count' => 1,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
