<?php

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\DealStatusLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealStatusLog>
 */
class DealStatusLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'old_status' => DealStatus::Open,
            'new_status' => DealStatus::Won,
        ];
    }
}
