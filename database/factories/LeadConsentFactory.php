<?php

namespace Database\Factories;

use App\Enums\ConsentSource;
use App\Enums\ConsentStatus;
use App\Enums\ConsentType;
use App\Models\Lead;
use App\Models\LeadConsent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadConsent>
 */
class LeadConsentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lead_id' => Lead::factory(),
            'consent_type' => ConsentType::Privacy,
            'status' => ConsentStatus::Granted,
            'source' => ConsentSource::Manual,
            'occurred_at' => now(),
        ];
    }

    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => ConsentStatus::Withdrawn]);
    }

    public function marketing(): static
    {
        return $this->state(fn (array $attributes): array => ['consent_type' => ConsentType::Marketing]);
    }
}
