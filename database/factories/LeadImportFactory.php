<?php

namespace Database\Factories;

use App\Enums\LeadImportStatus;
use App\Enums\LeadSourceType;
use App\Models\LeadImport;
use App\Models\LeadSource;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadImport>
 */
class LeadImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'source_id' => fn (array $attributes) => LeadSource::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'type' => LeadSourceType::CsvImport,
            ])->id,
            'original_filename' => fake()->word().'.csv',
            'file_path' => 'lead-imports/'.fake()->uuid().'.csv',
            'status' => LeadImportStatus::Pending,
            'total_rows' => null,
            'imported_count' => 0,
            'duplicate_count' => 0,
            'failed_count' => 0,
        ];
    }
}
