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
            // Created against the lead's own tenant_id (already resolved by
            // this point in attribute expansion) rather than letting
            // LeadSource::factory()'s own tenant_id default spawn an unrelated
            // orphan tenant — that extra tenant used to collide with
            // CreateClient's DB-count-based tenant_code numbering, since
            // TenantFactory numbers via its own static counter instead.
            'source_id' => fn (array $attributes) => LeadSource::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->id,
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'message' => fake()->sentence(),
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
        ];
    }
}
