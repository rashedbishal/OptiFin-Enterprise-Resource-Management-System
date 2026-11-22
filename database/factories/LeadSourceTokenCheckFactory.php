<?php

namespace Database\Factories;

use App\Enums\TokenCheckStatus;
use App\Models\LeadSource;
use App\Models\LeadSourceTokenCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadSourceTokenCheck>
 */
class LeadSourceTokenCheckFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_source_id' => LeadSource::factory(),
            'status' => TokenCheckStatus::Valid,
            'expires_at' => null,
            'expiry_known' => true,
            'error' => null,
            'checked_at' => now(),
        ];
    }

    public function invalid(): static
    {
        return $this->state(fn () => ['status' => TokenCheckStatus::Invalid, 'error' => 'Error validating access token: Session has expired.']);
    }

    public function expiringIn(int $days): static
    {
        return $this->state(fn () => ['expires_at' => now()->addDays($days), 'expiry_known' => true]);
    }
}
