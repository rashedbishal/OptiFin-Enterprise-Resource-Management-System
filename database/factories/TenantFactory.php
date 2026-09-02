<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Currency;
use App\Models\LeadStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected static int $sequence = 0;

    /**
     * Every factory-created tenant gets the full LeadStatus catalog as its
     * pipeline, in catalog order, and the catalog's oldest Currency — both
     * mirror what App\Actions\Platform\AssignModuleToTenant does in
     * production when Lead Management is activated for a client, so tests
     * don't each need to set up a pipeline/currency by hand before
     * creating leads or Lead Sources (LeadSources::create()/save() refuse
     * to create one for a tenant with zero currencies selected).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant) {
            $statusIds = LeadStatus::query()->orderBy('sort_order')->pluck('id');

            $tenant->leadStatuses()->attach(
                $statusIds->mapWithKeys(fn (string $id, int $index): array => [$id => ['position' => $index + 1]])->all()
            );

            $currency = Currency::query()->oldest()->first() ?? Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar']);

            $tenant->currencies()->attach($currency->id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'legal_name' => fake()->company().' '.fake()->companySuffix(),
            'tenant_code' => sprintf('TEN-%06d', ++static::$sequence),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'country' => fake()->country(),
            'timezone' => 'UTC',
            'status' => TenantStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Inactive]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Suspended]);
    }
}
