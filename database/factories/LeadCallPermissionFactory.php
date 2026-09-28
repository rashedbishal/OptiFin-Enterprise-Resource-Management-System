<?php

namespace Database\Factories;

use App\Enums\LeadCallPermissionStatus;
use App\Models\Lead;
use App\Models\LeadCallPermission;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadCallPermission>
 */
class LeadCallPermissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lead_id' => Lead::factory(),
            'lead_source_id' => LeadSource::factory(),
            'status' => LeadCallPermissionStatus::NoPermission,
        ];
    }

    public function requested(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeadCallPermissionStatus::Requested,
            'last_requested_at' => now(),
        ]);
    }

    public function temporary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeadCallPermissionStatus::Temporary,
            'meta_raw_status' => 'temporary',
            'expires_at' => now()->addDays(7),
            'last_synced_at' => now(),
        ]);
    }

    public function permanent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeadCallPermissionStatus::Permanent,
            'meta_raw_status' => 'permanent',
            'last_synced_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LeadCallPermissionStatus::Rejected,
            'last_synced_at' => now(),
        ]);
    }
}
