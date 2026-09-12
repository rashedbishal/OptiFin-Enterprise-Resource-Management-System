<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\LeadSource;
use App\Models\LeadSourceCost;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LeadSourceCost>
 */
class LeadSourceCostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'lead_source_id' => LeadSource::factory(),
            'month' => Carbon::now()->startOfMonth(),
            'amount' => fake()->randomFloat(2, 10, 1000),
            'currency_id' => Currency::factory(),
        ];
    }
}
