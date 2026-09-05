<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadNote>
 */
class LeadNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'tenant_user_id' => TenantUser::factory(),
            'note' => fake()->sentence(),
        ];
    }
}
