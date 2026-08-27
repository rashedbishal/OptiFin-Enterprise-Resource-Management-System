<?php

namespace Database\Factories;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'source_id' => LeadSource::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'message' => fake()->sentence(),
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
        ];
    }

    /**
     * The lead source nested factory always creates its own (random) tenant.
     * Force it back into the lead's actual tenant so `$lead->source` never
     * resolves to null once the tenant global scope applies.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Lead $lead) {
            LeadSource::query()->whereKey($lead->source_id)->update(['tenant_id' => $lead->tenant_id]);
        });
    }
}
