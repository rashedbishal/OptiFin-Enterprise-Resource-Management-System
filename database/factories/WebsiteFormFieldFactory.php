<?php

namespace Database\Factories;

use App\Enums\ConsentType;
use App\Enums\WebsiteFormFieldType;
use App\Enums\WebsiteFormFixedField;
use App\Models\Tenant;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebsiteFormField>
 */
class WebsiteFormFieldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'website_form_id' => WebsiteForm::factory(),
            'field_type' => WebsiteFormFieldType::Fixed,
            'fixed_field' => WebsiteFormFixedField::Email->value,
            'lead_custom_field_definition_id' => null,
            'sort_order' => 0,
        ];
    }

    public function fixed(WebsiteFormFixedField $field): static
    {
        return $this->state(fn (array $attributes): array => [
            'field_type' => WebsiteFormFieldType::Fixed,
            'fixed_field' => $field->value,
            'lead_custom_field_definition_id' => null,
        ]);
    }

    public function custom(string $definitionId): static
    {
        return $this->state(fn (array $attributes): array => [
            'field_type' => WebsiteFormFieldType::Custom,
            'fixed_field' => null,
            'lead_custom_field_definition_id' => $definitionId,
        ]);
    }

    public function consent(ConsentType $type, bool $required = false): static
    {
        return $this->state(fn (array $attributes): array => [
            'field_type' => WebsiteFormFieldType::Consent,
            'fixed_field' => null,
            'lead_custom_field_definition_id' => null,
            'consent_type' => $type,
            'is_required' => $required,
        ]);
    }
}
