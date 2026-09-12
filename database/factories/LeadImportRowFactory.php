<?php

namespace Database\Factories;

use App\Enums\LeadImportRowStatus;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadImportRow>
 */
class LeadImportRowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_import_id' => LeadImport::factory(),
            'row_number' => fake()->unique()->numberBetween(1, 100000),
            'status' => LeadImportRowStatus::Imported,
            'normalized_email' => null,
            'normalized_phone' => null,
            'raw_data' => ['name' => fake()->name()],
            'errors' => null,
        ];
    }
}
